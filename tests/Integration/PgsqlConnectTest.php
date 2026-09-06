<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Integration;

use Fiber;
use Kinetis\Persistence\ConnectionOptions;
use Kinetis\Persistence\Driver\PgsqlAsyncClient;
use Kinetis\Persistence\Exception\ConnectionException;
use ReflectionProperty;
use Revolt\EventLoop;
use Throwable;

/**
 * Connecting suspends on this driver, which is what makes these two
 * cases possible at all: several Fibers can be inside an opening attempt
 * at the same moment, and a client can be closed while one is.
 */
final class PgsqlConnectTest extends DriverCase
{
    private const int POOL_WIDTH = 2;

    /**
     * Every waiting Fiber sees an empty pool at the same moment, so
     * without a slot reserved for the whole of an attempt each would
     * start one of its own and the pool would open as many connections
     * as there are callers. The server's own view is the check: the
     * backends carrying this client's application_name never outnumber
     * the pool.
     */
    public function test_concurrent_callers_never_open_more_connections_than_the_pool_allows(): void
    {
        $name = 'kinetis-pool-' . \bin2hex(\random_bytes(6));
        $db = new PgsqlAsyncClient(...[...self::postgresArgs(), new ConnectionOptions(
            applicationName: $name,
            maxConnections: self::POOL_WIDTH,
        )]);
        $observer = new PgsqlAsyncClient(...[...self::postgresArgs(), new ConnectionOptions(maxConnections: 1)]);

        $tasks = [];

        for ($i = 0; $i < 8; $i++) {
            $tasks[] = static fn (): int => (int) $db->query('SELECT pg_sleep(0.05), 1 AS n')->fetchRow()['n'];
        }

        try {
            self::assertSame(\array_fill(0, 8, 1), self::concurrently($tasks));
            self::assertLessThanOrEqual(self::POOL_WIDTH, self::backendCount($observer, $name));
        } finally {
            $db->close();
            $observer->close();
        }
    }

    /**
     * A client closed while a connection is still being opened wakes the
     * Fiber inside that attempt, closes the half-open handle, and leaves
     * nothing behind for the attempt to publish — the caller is told the
     * client is closed instead of being handed a connection nothing owns.
     */
    public function test_closing_the_client_during_a_connection_attempt_publishes_nothing(): void
    {
        $db = new PgsqlAsyncClient(...[...self::postgresArgs(), new ConnectionOptions(maxConnections: 1)]);
        $caught = null;
        $attemptsLeftOpen = null;

        $caller = new Fiber(static function () use ($db, &$caught): void {
            try {
                $db->query('SELECT 1');
            } catch (Throwable $e) {
                $caught = $e;
            }
        });

        // Deferred callbacks run before the loop polls for I/O, so this
        // lands while the attempt is suspended on its first readiness
        // wait rather than after the handshake finished. What close()
        // leaves behind is read on the spot: an attempt still listed
        // there is one whose handle nothing closed and whose Fiber
        // nothing woke — and a loop torn down with the request would
        // strand both.
        EventLoop::defer(static function () use ($db, &$attemptsLeftOpen): void {
            $db->close();
            $attemptsLeftOpen = self::property($db, 'opening');
        });
        $caller->start();
        EventLoop::run();

        self::assertSame([], $attemptsLeftOpen);
        self::assertInstanceOf(ConnectionException::class, $caught);
        self::assertTrue($db->isClosed());
        self::assertSame([], self::property($db, 'connections'));
    }

    private static function backendCount(PgsqlAsyncClient $observer, string $applicationName): int
    {
        return (int) $observer
            ->execute('SELECT count(*) AS c FROM pg_stat_activity WHERE application_name = ?', [$applicationName])
            ->fetchRow()['c'];
    }

    private static function property(object $object, string $name): mixed
    {
        return new ReflectionProperty($object, $name)->getValue($object);
    }
}
