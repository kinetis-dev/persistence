<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Integration;

use Kinetis\Persistence\ConnectionOptions;
use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Exception\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The stale-connection policy against a genuinely killed server
 * session — the async drivers only, since the PDO drivers hold a single
 * lazy connection with no retry policy by design.
 *
 * maxConnections: 1 makes the sequence deterministic: the pool can only
 * ever hand back the one connection whose server side was killed, so
 * each step below is the driver's own behavior rather than a lucky pick
 * from several pooled connections.
 *
 * The sequence these tests pin is the *verified* one, which is narrower
 * than "a killed connection is always transparent":
 *
 *   1. The call right after the kill fails with a QueryException. The
 *      dispatch succeeded — writing to a socket whose peer is gone is
 *      buffered locally, not an error — so the death is only discovered
 *      while reaping the result, and a reap-phase failure is never
 *      retried: the statement may already have executed, and replaying
 *      a non-idempotent one would be worse than failing.
 *   2. The next call is transparent. That dispatch does fail
 *      immediately against the now-known-dead socket, which is exactly
 *      the StaleConnectionException path — the connection is marked
 *      broken, torn down, and the query retried once on a fresh one.
 *
 * So a dead pooled connection costs the caller exactly one error, never
 * a permanently poisoned pool.
 */
final class StaleConnectionRetryTest extends DriverCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function asyncDrivers(): iterable
    {
        yield 'mysqli-async' => ['mysqli-async'];
        yield 'pgsql-async' => ['pgsql-async'];
    }

    #[DataProvider('asyncDrivers')]
    public function test_a_killed_connection_costs_one_error_then_retries_onto_a_fresh_one(string $driver): void
    {
        $db = self::makeClient($driver, new ConnectionOptions(maxConnections: 1));
        $admin = self::makeClient($driver, new ConnectionOptions(maxConnections: 1));

        $killed = self::serverSessionId($driver, $db);
        self::killServerSession($driver, $admin, $killed);

        // Let the server finish tearing the session down; the client
        // must discover the death on its own, at its next call.
        \usleep(300_000);

        try {
            self::serverSessionId($driver, $db);
            self::fail('the first call after the kill is expected to surface the reap-phase failure');
        } catch (QueryException) {
            // Step 1: not retried, by design — see the class docblock.
        }

        // Step 2: the dispatch-phase retry, transparent to the caller.
        $fresh = self::serverSessionId($driver, $db);
        self::assertNotSame($killed, $fresh);

        // And the recovered connection is a normal, reusable one.
        self::assertSame($fresh, self::serverSessionId($driver, $db));

        $db->close();
        $admin->close();
    }

    #[DataProvider('asyncDrivers')]
    public function test_a_transaction_opens_normally_on_the_connection_recovered_after_a_kill(string $driver): void
    {
        $db = self::makeClient($driver, new ConnectionOptions(maxConnections: 1));
        $admin = self::makeClient($driver, new ConnectionOptions(maxConnections: 1));

        $db->query('DROP TABLE IF EXISTS stale_retry_test');
        $db->query('CREATE TABLE stale_retry_test (id ' . self::autoIncrementColumn($driver) . ', n INT NOT NULL)');

        self::killServerSession($driver, $admin, self::serverSessionId($driver, $db));
        \usleep(300_000);

        try {
            $db->query('SELECT 1');
        } catch (QueryException) {
            // The one expected error, as above.
        }

        // beginTransaction() has its own dispatch-retry loop; the whole
        // transaction has to land on the recovered connection.
        $tx = $db->beginTransaction();
        $tx->execute('INSERT INTO stale_retry_test (n) VALUES (?)', [7]);
        $tx->commit();

        $rows = \iterator_to_array($db->query('SELECT n FROM stale_retry_test'));
        self::assertCount(1, $rows);
        self::assertSame(7, $rows[0]['n']);

        $db->query('DROP TABLE stale_retry_test');
        $db->close();
        $admin->close();
    }

    private static function serverSessionId(string $driver, SqlLink $db): int
    {
        $sql = self::isMysql($driver) ? 'SELECT CONNECTION_ID() AS id' : 'SELECT pg_backend_pid() AS id';
        $row = $db->query($sql)->fetchRow();
        self::assertIsArray($row);

        return (int) $row['id'];
    }

    private static function killServerSession(string $driver, SqlLink $admin, int $id): void
    {
        if (self::isMysql($driver)) {
            // KILL takes no bound parameters; $id was read from the
            // server itself, never from external input.
            $admin->query("KILL {$id}");

            return;
        }

        $admin->execute('SELECT pg_terminate_backend(?)', [$id]);
    }
}
