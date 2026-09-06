<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Integration;

use Fiber;
use Kinetis\Persistence\ConnectionOptions;
use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Driver\PgsqlAsyncClient;
use Kinetis\Persistence\Exception\ConnectionException;
use PHPUnit\Framework\Attributes\DataProvider;
use Revolt\EventLoop;
use Throwable;

/**
 * Taking a Postgres connection out of service without waiting on it.
 *
 * ext-pgsql closes a connection by reading every outstanding result
 * first, so a statement still running costs the whole event loop that
 * wait — and a connection the server has put into COPY would wait for
 * input that never comes. Disposal ends the transport instead, which is
 * the one path both cases take.
 *
 * COPY reaches it because the driver has no protocol for the streaming
 * mode it switches the connection into: the exchange is lost rather than
 * the statement refused, so the caller gets a ConnectionException and
 * the pool replaces the connection.
 */
final class PgsqlDisposalTest extends DriverCase
{
    /**
     * Both transports libpq offers, since ending one is what disposal
     * does: a TCP connection, and a Unix-domain socket where
     * `POSTGRES_SOCKET_DIR` points at a server's socket directory.
     * TLS has its own case in TlsTest, where the certificates live.
     *
     * @return iterable<string, array{?string}>
     */
    public static function transports(): iterable
    {
        yield 'tcp' => [null];
        yield 'unix-domain' => ['POSTGRES_SOCKET_DIR'];
    }

    /**
     * Closing a client with a statement in flight must cost nothing near
     * what the statement had left to run.
     */
    #[DataProvider('transports')]
    public function test_disposal_abandons_a_statement_in_flight(?string $socketDirectory): void
    {
        $db = new PgsqlAsyncClient(...[
            ...self::transportArgs($socketDirectory),
            new ConnectionOptions(maxConnections: 1),
        ]);
        // Connected up front, so starting the Fiber below dispatches
        // rather than opening a connection.
        $db->warmUp(1);

        $caught = null;
        $caller = new Fiber(static function () use ($db, &$caught): void {
            try {
                $db->query('SELECT pg_sleep(5)');
            } catch (Throwable $e) {
                $caught = $e;
            }
        });
        $caller->start();

        $started = \microtime(true);
        $db->close();
        $spent = \microtime(true) - $started;

        // The caller is settled from a loop callback, so it comes back
        // here rather than inside close().
        EventLoop::run();

        self::assertLessThan(1.0, $spent, 'Disposal must not wait on the statement it abandons.');
        self::assertInstanceOf(ConnectionException::class, $caught);
        self::assertStringContainsString('unknown', $caught->getMessage());
    }

    public function test_copy_is_refused_without_stalling_the_event_loop(): void
    {
        $db = self::makeClient('pgsql-async');
        self::createTable($db, 'copy_reject');
        $order = [];

        self::concurrently([
            static function () use ($db, &$order): void {
                // Outstanding on its own pooled connection while the COPY
                // below is refused and its connection taken out of
                // service. Reaching the rows at all means the loop kept
                // running through that: this Fiber can only resume from
                // one of its turns.
                $db->query('SELECT pg_sleep(0.3)');

                for ($i = 0; $i < 3; $i++) {
                    $db->execute('INSERT INTO copy_reject (s) VALUES (?)', ["sibling-{$i}"]);
                }

                $order[] = 'sibling';
            },
            static function () use ($db, &$order): void {
                try {
                    $db->query('COPY copy_reject FROM STDIN');
                    self::fail('Expected COPY to be refused.');
                } catch (ConnectionException $e) {
                    self::assertStringContainsString('COPY is not supported', $e->getMessage());
                }

                $order[] = 'copy';
            },
        ]);

        self::assertSame(['copy', 'sibling'], $order);
        self::assertSame(3, self::rowCount($db, 'copy_reject'));

        $db->query('DROP TABLE copy_reject');
        $db->close();
    }

    /**
     * The connection COPY broke is replaced rather than pooled. With
     * room for exactly one, the query after it can only be answered on a
     * new connection — a different backend, which is also what proves
     * the server let the old session go.
     */
    public function test_the_pool_replaces_the_connection_copy_broke(): void
    {
        $db = self::makeClient('pgsql-async', new ConnectionOptions(maxConnections: 1));
        self::createTable($db, 'copy_pool');
        $first = self::backendPid($db);

        try {
            $db->query('COPY copy_pool FROM STDIN');
            self::fail('Expected COPY to be refused.');
        } catch (ConnectionException) {
        }

        self::assertNotSame($first, self::backendPid($db));

        $db->query('DROP TABLE copy_pool');
        $db->close();
    }

    /**
     * The connection arguments for one transport: the ordinary ones, or
     * the same server addressed through the socket directory named by
     * $environmentKey — libpq reads a host starting with "/" as one.
     *
     * @return array{string, string, string, string, int}
     */
    private static function transportArgs(?string $environmentKey): array
    {
        $args = self::postgresArgs();

        if ($environmentKey === null) {
            return $args;
        }

        $directory = \getenv($environmentKey);

        if ($directory === false) {
            self::markTestSkipped("{$environmentKey} is not set — the Unix-domain case is environment-gated.");
        }

        $args[0] = $directory;

        return $args;
    }

    private static function createTable(SqlLink $db, string $table): void
    {
        $db->query("DROP TABLE IF EXISTS {$table}");
        $db->query("CREATE TABLE {$table} (id SERIAL PRIMARY KEY, s VARCHAR(32) NOT NULL)");
    }

    private static function rowCount(SqlLink $db, string $table): int
    {
        return (int) $db->query("SELECT COUNT(*) AS c FROM {$table}")->fetchRow()['c'];
    }

    private static function backendPid(SqlLink $db): int
    {
        return (int) $db->query('SELECT pg_backend_pid() AS pid')->fetchRow()['pid'];
    }
}
