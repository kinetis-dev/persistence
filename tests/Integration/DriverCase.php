<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Integration;

use Kinetis\Persistence\ConnectionOptions;
use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Driver\MysqliAsyncClient;
use Kinetis\Persistence\Driver\PdoMysqlClient;
use Kinetis\Persistence\Driver\PdoPgsqlClient;
use Kinetis\Persistence\Driver\PgsqlAsyncClient;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

/**
 * Base for the real-backend driver tests: environment-gated (each test
 * skips unless its backend's *_HOST env var is set, so plain local
 * `vendor/bin/phpunit` runs stay database-free), with one client
 * factory per driver and the Fiber fan-out helper the async drivers are
 * exercised through.
 *
 * CI provides the env in two places: the Integration workflow's
 * persistence job (both MySQL flavors + Postgres) and the SonarQube
 * coverage job — the latter is what makes this real driver exercise
 * count as measured coverage rather than out-of-band verification.
 */
abstract class DriverCase extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function drivers(): iterable
    {
        yield 'mysqli-async' => ['mysqli-async'];
        yield 'pdo-mysql' => ['pdo-mysql'];
        yield 'pgsql-async' => ['pgsql-async'];
        yield 'pdo-pgsql' => ['pdo-pgsql'];
    }

    protected static function makeClient(string $driver, ?ConnectionOptions $options = null): SqlLink
    {
        $mysqlArgs = \str_contains($driver, 'mysql') ? self::mysqlArgs() : [];
        $postgresArgs = \str_contains($driver, 'pgsql') ? self::postgresArgs() : [];

        return match ($driver) {
            'mysqli-async' => new MysqliAsyncClient(...[...$mysqlArgs, $options ?? new ConnectionOptions(maxConnections: 4)]),
            'pdo-mysql' => new PdoMysqlClient(...[...$mysqlArgs, $options]),
            'pgsql-async' => new PgsqlAsyncClient(...[...$postgresArgs, $options ?? new ConnectionOptions(maxConnections: 4)]),
            'pdo-pgsql' => new PdoPgsqlClient(...[...$postgresArgs, $options]),
        };
    }

    /**
     * The connection arguments every Postgres client here is built from,
     * in constructor order. Skips the calling test when no server is
     * configured, which is how the whole suite stays database-free
     * outside the integration jobs.
     *
     * @return array{string, string, string, string, int}
     */
    protected static function postgresArgs(): array
    {
        $host = \getenv('POSTGRES_HOST');

        if ($host === false) {
            self::markTestSkipped('POSTGRES_HOST is not set — real-backend driver tests are environment-gated.');
        }

        return [
            (string) $host,
            \getenv('POSTGRES_USER') ?: 'testuser',
            \getenv('POSTGRES_PASSWORD') ?: 'testpass',
            \getenv('POSTGRES_DATABASE') ?: 'testdb',
            (int) (\getenv('POSTGRES_PORT') ?: 5432),
        ];
    }

    /**
     * @return array{string, string, string, string, int}
     */
    protected static function mysqlArgs(): array
    {
        $host = \getenv('MYSQL_HOST');

        if ($host === false) {
            self::markTestSkipped('MYSQL_HOST is not set — real-backend driver tests are environment-gated.');
        }

        return [
            (string) $host,
            \getenv('MYSQL_USER') ?: 'testuser',
            \getenv('MYSQL_PASSWORD') ?: 'testpass',
            \getenv('MYSQL_DATABASE') ?: 'testdb',
            (int) (\getenv('MYSQL_PORT') ?: 3306),
        ];
    }

    /**
     * The server's own id for the session $db is running on —
     * CONNECTION_ID() on MySQL, pg_backend_pid() on Postgres. It is what
     * makes "the same connection" and "a different one" the server's
     * answer rather than the client's claim.
     */
    protected static function serverSessionId(string $driver, SqlLink $db): int
    {
        $sql = self::isMysql($driver) ? 'SELECT CONNECTION_ID() AS id' : 'SELECT pg_backend_pid() AS id';
        $row = $db->query($sql)->fetchRow();
        self::assertIsArray($row);

        return (int) $row['id'];
    }

    protected static function isMysql(string $driver): bool
    {
        return \str_contains($driver, 'mysql');
    }

    protected static function autoIncrementColumn(string $driver): string
    {
        return self::isMysql($driver) ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'SERIAL PRIMARY KEY';
    }

    /**
     * The same Fiber fan-out shape Kinetis\Async\concurrently() uses —
     * inlined here so this package's tests don't depend on the framework
     * helper's presence.
     *
     * @param list<callable(): mixed> $tasks
     * @return list<mixed>
     */
    protected static function concurrently(array $tasks): array
    {
        $fibers = [];

        foreach ($tasks as $index => $task) {
            $fiber = new \Fiber(static function () use ($task): array {
                try {
                    return ['ok', $task()];
                } catch (\Throwable $e) {
                    return ['error', $e];
                }
            });
            $fibers[$index] = $fiber;
            $fiber->start();
        }

        EventLoop::run();

        $results = [];

        foreach ($fibers as $index => $fiber) {
            [$status, $value] = $fiber->getReturn();

            if ($status === 'error') {
                throw $value;
            }

            $results[$index] = $value;
        }

        return $results;
    }
}
