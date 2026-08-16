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
        yield 'mysqli rejects applicationName' => [MysqliAsyncClient::class, new ConnectionOptions(applicationName: 'x')];
        yield 'mysqli rejects extraConnectionString' => [MysqliAsyncClient::class, new ConnectionOptions(extraConnectionString: 'a=b')];
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
        yield 'mysqli accepts require and both verify ssl modes' => [
            MysqliAsyncClient::class,
            new ConnectionOptions(sslMode: 'verify-ca', sslCa: '/certs/ca.pem'),
        ];
        yield 'pdo-mysql accepts require and both verify ssl modes' => [
            PdoMysqlClient::class,
            new ConnectionOptions(sslMode: 'verify-full', sslCa: '/certs/ca.pem'),
        ];
        yield 'pgsql accepts a ca bundle path' => [
            PgsqlAsyncClient::class,
            new ConnectionOptions(sslMode: 'verify-full', sslCa: '/certs/ca.pem'),
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

    /**
     * @return iterable<string, array{class-string, ConnectionOptions, string}>
     */
    public static function invalidMysqlSslProfiles(): iterable
    {
        yield 'mysqli rejects opportunistic allow' => [
            MysqliAsyncClient::class,
            new ConnectionOptions(sslMode: 'allow'),
            'The native mysqli driver has no opportunistic TLS: $sslMode "allow" is libpq-only. Use "disable", "require", "verify-ca", or "verify-full".',
        ];
        yield 'pdo-mysql rejects opportunistic prefer' => [
            PdoMysqlClient::class,
            new ConnectionOptions(sslMode: 'prefer'),
            'The PDO mysql driver has no opportunistic TLS: $sslMode "prefer" is libpq-only. Use "disable", "require", "verify-ca", or "verify-full".',
        ];
        yield 'mysqli rejects verify-ca without a ca bundle' => [
            MysqliAsyncClient::class,
            new ConnectionOptions(sslMode: 'verify-ca'),
            'The native mysqli driver needs $sslCa (a CA bundle path) for $sslMode "verify-ca" — there is nothing to verify the server certificate against without one.',
        ];
        yield 'pdo-mysql rejects verify-full without a ca bundle' => [
            PdoMysqlClient::class,
            new ConnectionOptions(sslMode: 'verify-full'),
            'The PDO mysql driver needs $sslCa (a CA bundle path) for $sslMode "verify-full" — there is nothing to verify the server certificate against without one.',
        ];
        yield 'mysqli rejects a ca bundle under require' => [
            MysqliAsyncClient::class,
            new ConnectionOptions(sslMode: 'require', sslCa: '/certs/ca.pem'),
            'The native mysqli driver would silently ignore $sslCa under $sslMode "require" — set $sslMode to "verify-ca" or "verify-full", or unset $sslCa.',
        ];
        yield 'pdo-mysql rejects a ca bundle with no ssl mode at all' => [
            PdoMysqlClient::class,
            new ConnectionOptions(sslCa: '/certs/ca.pem'),
            'The PDO mysql driver would silently ignore $sslCa under $sslMode "unset" — set $sslMode to "verify-ca" or "verify-full", or unset $sslCa.',
        ];
    }

    /**
     * @param class-string $driverClass
     */
    #[DataProvider('invalidMysqlSslProfiles')]
    public function test_mysql_drivers_reject_incoherent_tls_profiles(string $driverClass, ConnectionOptions $options, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        new $driverClass('h', 'u', 'p', 'db', 3306, $options);
    }

    public function test_pgsql_drivers_keep_accepting_opportunistic_ssl_modes(): void
    {
        // libpq's allow/prefer stay valid where libpq is the backend.
        $options = new ConnectionOptions(sslMode: 'prefer');

        self::assertSame($options, self::property(new PgsqlAsyncClient('h', 'u', 'p', 'db', 5432, $options), 'options'));
        self::assertSame($options, self::property(new PdoPgsqlClient('h', 'u', 'p', 'db', 5432, $options), 'options'));
    }

    public function test_a_client_certificate_without_its_key_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'ConnectionOptions $sslCert and $sslKey must be set together — a client certificate is '
            . 'unusable without its private key, and vice versa.',
        );
        new ConnectionOptions(sslMode: 'require', sslCert: '/certs/client.pem');
    }

    public function test_a_client_key_without_its_certificate_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be set together');
        new ConnectionOptions(sslMode: 'require', sslKey: '/certs/client.key');
    }

    public function test_a_client_certificate_without_tls_is_rejected(): void
    {
        // Silently ignoring it would look like mutual TLS was configured.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'ConnectionOptions $sslCert/$sslKey need TLS: set $sslMode to "require", "verify-ca", '
            . '"verify-full" (or libpq\'s "allow"/"prefer"), since a client certificate is only ever '
            . 'presented during a TLS handshake.',
        );
        new ConnectionOptions(sslCert: '/certs/client.pem', sslKey: '/certs/client.key');
    }

    public function test_a_client_certificate_with_ssl_mode_disable_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('need TLS');
        new ConnectionOptions(sslMode: 'disable', sslCert: '/certs/client.pem', sslKey: '/certs/client.key');
    }

    public function test_an_empty_client_certificate_path_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ConnectionOptions $sslCert must be a file path, not an empty string.');
        new ConnectionOptions(sslMode: 'require', sslCert: '', sslKey: '/certs/client.key');
    }

    /**
     * Mutual TLS is orthogonal to *server* verification: presenting a
     * client certificate is valid under every mode that performs a
     * handshake, including the non-verifying "require".
     */
    #[DataProvider('everyDriver')]
    public function test_every_driver_accepts_mutual_tls(string $driverClass, int $port): void
    {
        $options = new ConnectionOptions(
            sslMode: 'verify-full',
            sslCa: '/certs/ca.pem',
            sslCert: '/certs/client.pem',
            sslKey: '/certs/client.key',
        );

        self::assertSame($options, self::property(new $driverClass('h', 'u', 'p', 'db', $port, $options), 'options'));
    }

    /**
     * @return iterable<string, array{class-string, int}>
     */
    public static function everyDriver(): iterable
    {
        yield 'mysqli-async' => [MysqliAsyncClient::class, 3306];
        yield 'pdo-mysql' => [PdoMysqlClient::class, 3306];
        yield 'pgsql-async' => [PgsqlAsyncClient::class, 5432];
        yield 'pdo-pgsql' => [PdoPgsqlClient::class, 5432];
    }
}
