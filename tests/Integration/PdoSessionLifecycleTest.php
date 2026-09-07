<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Integration;

use Fiber;
use Kinetis\Config\Config;
use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Exception\ConnectionException;
use Kinetis\Persistence\SqlConnectionFactory;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What a PDO client does with the physical session it holds, against a
 * real server: an ordinary client replaces a session it can carry no
 * more work on, and a single-session client closes instead.
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

    /**
     * The replacement is a session of the server's own — a different
     * backend, kept from then on — and the work the discarded session
     * was carrying went back with it.
     */
    #[DataProvider('pdoDrivers')]
    public function test_a_discarded_session_is_replaced_and_takes_its_work_with_it(string $driver): void
    {
        $db = self::makeClient($driver);
        // Dropped on the way in: the session this test discards may
        // still be tearing down on the server afterwards, and a DROP
        // would wait on the table lock it is holding.
        $db->query('DROP TABLE IF EXISTS pdo_session_lifecycle');
        $db->query('CREATE TABLE pdo_session_lifecycle (id ' . self::autoIncrementColumn($driver) . ', n INT NOT NULL)');
        $discarded = self::serverSessionId($driver, $db);

        $transaction = $db->beginTransaction();
        $transaction->execute('INSERT INTO pdo_session_lifecycle (n) VALUES (?)', [7]);
        new Fiber(static fn () => $transaction->close())->start();

        self::assertFalse($transaction->isActive());
        self::assertFalse($db->isClosed());

        $fresh = self::serverSessionId($driver, $db);
        self::assertNotSame($discarded, $fresh);
        // And it is the client's session from here, not a connection
        // opened for one statement.
        self::assertSame($fresh, self::serverSessionId($driver, $db));
        self::assertSame([], \iterator_to_array($db->query('SELECT n FROM pdo_session_lifecycle')));

        $db->close();
    }

    /**
     * The single-session client kinetis/migrations runs on. Its advisory
     * lock lives in the session, so a replacement would be an unlocked
     * one: it closes where the session went, and every later call says
     * so.
     */
    #[DataProvider('pdoDrivers')]
    public function test_a_single_session_client_closes_where_its_session_went(string $driver): void
    {
        $db = self::singleSessionClient($driver);
        self::serverSessionId($driver, $db);

        $transaction = $db->beginTransaction();
        new Fiber(static fn () => $transaction->close())->start();

        self::assertTrue($db->isClosed());
        $this->expectException(ConnectionException::class);
        $db->query('SELECT 1');
    }

    /**
     * Built the way the migrate:* commands build theirs, so the policy
     * is proven on the client a deployment gets rather than one the test
     * pinned by hand.
     */
    private static function singleSessionClient(string $driver): SqlLink
    {
        $mysql = self::isMysql($driver);
        [$host, $user, $password, $database, $port] = $mysql ? self::mysqlArgs() : self::postgresArgs();

        return SqlConnectionFactory::singleSession(new Config([
            'DB_CONNECTION' => $mysql ? 'mysql' : 'pgsql',
            'DB_HOST' => $host,
            'DB_USER' => $user,
            'DB_PASSWORD' => $password,
            'DB_NAME' => $database,
            'DB_PORT' => (string) $port,
        ]));
    }
}
