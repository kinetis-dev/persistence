<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Integration;

use Kinetis\Persistence\ConnectionOptions;
use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Driver\StaleConnectionException;
use Kinetis\Persistence\Exception\ConnectionException;
use Kinetis\Persistence\Exception\QueryException;
use Kinetis\Persistence\Exception\SqlException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The stale-connection policy against a killed server session — the
 * async drivers only, since retrying a statement means moving it to
 * another connection and only a pool has one. What a PDO client does
 * with a session it can no longer use is
 * {@see PdoSessionLifecycleTest}.
 *
 * maxConnections: 1 makes the sequence deterministic: the pool can only
 * ever hand back the one connection whose server side was killed, so
 * each step below is the driver's own behavior rather than a lucky pick
 * from several pooled connections.
 *
 * The sequence these tests pin is narrower than "a killed connection is
 * always transparent":
 *
 *   1. The call right after the kill fails, and never with a
 *      StaleConnectionException — that class means "retry", and this
 *      statement may already have executed. The dispatch succeeded —
 *      writing to a socket whose peer is gone is buffered locally, not
 *      an error — so the death is only discovered while reaping the
 *      result. Which failure the caller sees is what each client can
 *      tell: mysqli reports the session gone (a ConnectionException,
 *      which is also what ends and discards a transaction pinned to
 *      that connection), while Postgres answers with a real error
 *      result before the socket closes (a QueryException).
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
        } catch (SqlException $e) {
            // Step 1: not retried, by design — see the class docblock.
            self::assertNotInstanceOf(StaleConnectionException::class, $e);
            self::assertInstanceOf(
                self::isMysql($driver) ? ConnectionException::class : QueryException::class,
                $e,
            );
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
        } catch (SqlException) {
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

    /**
     * A transaction ends with the connection it is pinned to. mysqli
     * alone, because only there does the client itself report the
     * session gone: Postgres answers the statement with an error result
     * first, which is an aborted transaction rather than a lost one.
     */
    public function test_a_transaction_ends_when_its_pinned_connection_dies(): void
    {
        $db = self::makeClient('mysqli-async', new ConnectionOptions(maxConnections: 1));
        $admin = self::makeClient('mysqli-async', new ConnectionOptions(maxConnections: 1));

        $transaction = $db->beginTransaction();
        self::killServerSession('mysqli-async', $admin, self::serverSessionId('mysqli-async', $transaction));
        \usleep(300_000);

        try {
            $transaction->query('SELECT 1');
            self::fail('the statement on a killed session is expected to report the connection lost');
        } catch (ConnectionException) {
        }

        // Ended with the session rather than left believing it is open,
        // and the dead connection discarded rather than pooled: the
        // client's one connection slot is free for a fresh one.
        self::assertFalse($transaction->isActive());
        self::assertNotSame(0, self::serverSessionId('mysqli-async', $db));

        $db->close();
        $admin->close();
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
