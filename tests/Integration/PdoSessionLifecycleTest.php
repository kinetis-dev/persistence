<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Integration;

use Fiber;
use Kinetis\Persistence\Exception\ConnectionException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What a PDO client does with the physical session it holds, against a
 * real server: a session that can carry no more work is handed back and
 * replaced on the next call, while close() ends the client itself.
 *
 * The server's own session id is the evidence — MySQL's
 * CONNECTION_ID(), Postgres's pg_backend_pid() — so "a fresh
 * connection" is the server's answer rather than the client's claim.
 *
 * A transaction closed from a foreign Fiber is the discard these tests
 * provoke: it ends without a ROLLBACK on the wire, so its session goes
 * back to the server to roll the work back with. `DB_DRIVER=auto`
 * selects PDO for every process that is not a persistent worker, a
 * queue worker included, so a client left holding that session would
 * serve every later job on a connection the server has already
 * discarded.
 */
final class PdoSessionLifecycleTest extends DriverCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function pdoDrivers(): iterable
    {
        yield 'pdo-mysql' => ['pdo-mysql'];
        yield 'pdo-pgsql' => ['pdo-pgsql'];
    }

    #[DataProvider('pdoDrivers')]
    public function test_a_discarded_session_is_replaced_on_the_next_call(string $driver): void
    {
        $db = self::makeClient($driver);
        $discarded = self::serverSessionId($driver, $db);

        $transaction = $db->beginTransaction();
        new Fiber(static fn () => $transaction->close())->start();

        self::assertFalse($transaction->isActive());
        self::assertFalse($db->isClosed());

        $fresh = self::serverSessionId($driver, $db);
        self::assertNotSame($discarded, $fresh);

        // And the replacement is an ordinary session the client keeps,
        // not a connection opened for one statement.
        self::assertSame($fresh, self::serverSessionId($driver, $db));

        $db->close();
    }

    /**
     * The work of a transaction whose session was discarded is gone —
     * the server rolled it back as the session went — and the client
     * carries an ordinary transaction on the replacement.
     */
    #[DataProvider('pdoDrivers')]
    public function test_the_discarded_session_takes_its_uncommitted_work_with_it(string $driver): void
    {
        $db = self::makeClient($driver);
        // Dropped on the way in: the session this test discards may
        // still be tearing down on the server afterwards, and a DROP
        // would wait on the table lock it is holding.
        $db->query('DROP TABLE IF EXISTS pdo_session_lifecycle');
        $db->query('CREATE TABLE pdo_session_lifecycle (id ' . self::autoIncrementColumn($driver) . ', n INT NOT NULL)');

        $transaction = $db->beginTransaction();
        $transaction->execute('INSERT INTO pdo_session_lifecycle (n) VALUES (?)', [7]);
        new Fiber(static fn () => $transaction->close())->start();

        self::assertSame([], \iterator_to_array($db->query('SELECT n FROM pdo_session_lifecycle')));

        $second = $db->beginTransaction();
        $second->execute('INSERT INTO pdo_session_lifecycle (n) VALUES (?)', [8]);
        $second->commit();

        $rows = \iterator_to_array($db->query('SELECT n FROM pdo_session_lifecycle'));
        self::assertCount(1, $rows);
        self::assertSame(8, (int) $rows[0]['n']);

        $db->close();
    }

    /**
     * close() is the ending a client does not come back from, whether or
     * not a session was discarded first, and it is idempotent.
     */
    #[DataProvider('pdoDrivers')]
    public function test_close_stays_final_after_a_discarded_session(string $driver): void
    {
        $db = self::makeClient($driver);

        $transaction = $db->beginTransaction();
        new Fiber(static fn () => $transaction->close())->start();
        self::serverSessionId($driver, $db);

        $db->close();
        $db->close();

        self::assertTrue($db->isClosed());
        $this->expectException(ConnectionException::class);
        $db->query('SELECT 1');
    }
}
