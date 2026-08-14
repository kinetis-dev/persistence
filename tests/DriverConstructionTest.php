<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use InvalidArgumentException;
use Kinetis\Persistence\ConnectionOptions;
use Kinetis\Persistence\Driver\MysqliAsyncClient;
use Kinetis\Persistence\Driver\PdoMysqlClient;
use Kinetis\Persistence\Driver\PdoPgsqlClient;
use Kinetis\Persistence\Driver\PgsqlAsyncClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Construction-time wiring contracts, no database needed: default ports,
 * the default ConnectionOptions fallback, and — most importantly — that
 * every driver rejects each individual option it documents as
 * unsupported (see docs/persistence.md's support matrix). Losing any one
 * entry from a driver's rejection list would silently turn a loud
 * failure into an ignored option.
 */
final class DriverConstructionTest extends TestCase
{
    private static function property(object $object, string $name): mixed
    {
        return new ReflectionProperty($object, $name)->getValue($object);
    }

    public function test_mysql_drivers_default_to_port_3306_and_pg_drivers_to_5432(): void
    {
        self::assertSame(3306, self::property(new MysqliAsyncClient('h', 'u', 'p', 'db'), 'port'));
        self::assertSame(3306, self::property(new PdoMysqlClient('h', 'u', 'p', 'db'), 'port'));
        self::assertSame(5432, self::property(new PgsqlAsyncClient('h', 'u', 'p', 'db'), 'port'));
        self::assertSame(5432, self::property(new PdoPgsqlClient('h', 'u', 'p', 'db'), 'port'));
    }

    public function test_an_explicit_port_wins_over_the_default(): void
    {
        self::assertSame(13306, self::property(new MysqliAsyncClient('h', 'u', 'p', 'db', 13306), 'port'));
        self::assertSame(15432, self::property(new PgsqlAsyncClient('h', 'u', 'p', 'db', 15432), 'port'));
    }

    public function test_omitted_options_become_a_default_connection_options_instance(): void
    {
        foreach ([
            new MysqliAsyncClient('h', 'u', 'p', 'db'),
            new PgsqlAsyncClient('h', 'u', 'p', 'db'),
            new PdoMysqlClient('h', 'u', 'p', 'db'),
            new PdoPgsqlClient('h', 'u', 'p', 'db'),
        ] as $client) {
            $options = self::property($client, 'options');
            self::assertInstanceOf(ConnectionOptions::class, $options);
            self::assertSame(8, $options->maxConnections);
        }
    }

    public function test_explicit_options_are_kept(): void
    {
        $options = new ConnectionOptions(maxConnections: 3);

        self::assertSame($options, self::property(new MysqliAsyncClient('h', 'u', 'p', 'db', 3306, $options), 'options'));
        self::assertSame($options, self::property(new PgsqlAsyncClient('h', 'u', 'p', 'db', 5432, $options), 'options'));
    }

    /**
     * @return iterable<string, array{class-string, ConnectionOptions}>
     */
    public static function unsupportedPerDriver(): iterable
    {
        // The documented support matrix, inverted: each driver x each
        // option it must reject.
        yield 'mysqli rejects sslMode' => [MysqliAsyncClient::class, new ConnectionOptions(sslMode: 'require')];
        yield 'mysqli rejects applicationName' => [MysqliAsyncClient::class, new ConnectionOptions(applicationName: 'x')];
        yield 'mysqli rejects extraConnectionString' => [MysqliAsyncClient::class, new ConnectionOptions(extraConnectionString: 'a=b')];
        yield 'pdo-mysql rejects sslMode' => [PdoMysqlClient::class, new ConnectionOptions(sslMode: 'require')];
        yield 'pdo-mysql rejects applicationName' => [PdoMysqlClient::class, new ConnectionOptions(applicationName: 'x')];
        yield 'pdo-mysql rejects extraConnectionString' => [PdoMysqlClient::class, new ConnectionOptions(extraConnectionString: 'a=b')];
        yield 'pgsql rejects collation' => [PgsqlAsyncClient::class, new ConnectionOptions(collation: 'foo_ci')];
        yield 'pgsql rejects compression' => [PgsqlAsyncClient::class, new ConnectionOptions(compression: true)];
        yield 'pdo-pgsql rejects collation' => [PdoPgsqlClient::class, new ConnectionOptions(collation: 'foo_ci')];
        yield 'pdo-pgsql rejects compression' => [PdoPgsqlClient::class, new ConnectionOptions(compression: true)];
    }

    /**
     * @param class-string $driverClass
     */
    #[DataProvider('unsupportedPerDriver')]
    public function test_each_driver_rejects_each_documented_unsupported_option(string $driverClass, ConnectionOptions $options): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not support');
        new $driverClass('h', 'u', 'p', 'db', 3306, $options);
    }

    /**
     * @return iterable<string, array{class-string, ConnectionOptions}>
     */
    public static function supportedPerDriver(): iterable
    {
        // And the matrix right side up: options each driver must accept
        // at construction (none of these connect yet — all drivers are
        // lazy).
        yield 'mysqli accepts charset/collation/timeout/compression' => [
            MysqliAsyncClient::class,
            new ConnectionOptions(charset: 'utf8mb4', collation: 'utf8mb4_unicode_ci', connectTimeout: 3, compression: true),
        ];
        yield 'pdo-mysql accepts charset/collation/timeout/compression' => [
            PdoMysqlClient::class,
            new ConnectionOptions(charset: 'utf8mb4', collation: 'utf8mb4_unicode_ci', connectTimeout: 3, compression: true),
        ];
        yield 'pgsql accepts charset/ssl/timeout/appname/extra' => [
            PgsqlAsyncClient::class,
            new ConnectionOptions(charset: 'UTF8', sslMode: 'require', connectTimeout: 3, applicationName: 'x', extraConnectionString: 'a=b'),
        ];
        yield 'pdo-pgsql accepts charset/ssl/timeout/appname/extra' => [
            PdoPgsqlClient::class,
            new ConnectionOptions(charset: 'UTF8', sslMode: 'require', connectTimeout: 3, applicationName: 'x', extraConnectionString: 'a=b'),
        ];
    }

    /**
     * @param class-string $driverClass
     */
    #[DataProvider('supportedPerDriver')]
    public function test_each_driver_accepts_its_documented_supported_options(string $driverClass, ConnectionOptions $options): void
    {
        $client = new $driverClass('h', 'u', 'p', 'db', 3306, $options);

        self::assertSame($options, self::property($client, 'options'));
    }
}
