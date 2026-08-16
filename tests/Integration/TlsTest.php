<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Integration;

use Kinetis\Persistence\ConnectionOptions;
use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Driver\MysqliAsyncClient;
use Kinetis\Persistence\Driver\PdoMysqlClient;
use Kinetis\Persistence\Driver\PdoPgsqlClient;
use Kinetis\Persistence\Driver\PgsqlAsyncClient;
use Kinetis\Persistence\Exception\ConnectionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * TLS against real, certificate-configured servers — environment-gated
 * like every other real-backend test here. Skips unless MYSQL_TLS_HOST/
 * POSTGRES_TLS_HOST are set, together with TLS_CA (the CA bundle the
 * servers' certificates chain to) and TLS_WRONG_CA (a CA they do not,
 * for the fail-closed checks). The MySQL user must be created with
 * REQUIRE SSL, so a plaintext connection is refused — that refusal is
 * what proves the TLS connections are genuinely negotiated rather than
 * silently falling back to cleartext.
 */
final class TlsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function mysqlDrivers(): iterable
    {
        yield 'mysqli-async' => ['mysqli-async'];
        yield 'pdo-mysql' => ['pdo-mysql'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pgsqlDrivers(): iterable
    {
        yield 'pgsql-async' => ['pgsql-async'];
        yield 'pdo-pgsql' => ['pdo-pgsql'];
    }

    #[DataProvider('mysqlDrivers')]
    public function test_require_negotiates_tls_on_mysql(string $driver): void
    {
        $db = self::mysqlClient($driver, new ConnectionOptions(sslMode: 'require'));

        self::assertNotSame('', self::mysqlCipher($db), 'Ssl_cipher must be non-empty over TLS');
        $db->close();
    }

    #[DataProvider('mysqlDrivers')]
    public function test_a_plaintext_connection_to_the_require_ssl_user_is_refused(string $driver): void
    {
        // The control experiment: if this connected, the cipher checks
        // above would not prove anything.
        $db = self::mysqlClient($driver, new ConnectionOptions());

        $this->expectException(ConnectionException::class);
        $db->query('SELECT 1');
    }

    #[DataProvider('mysqlDrivers')]
    public function test_verify_full_accepts_the_real_ca_on_mysql(string $driver): void
    {
        $db = self::mysqlClient($driver, new ConnectionOptions(
            sslMode: 'verify-full',
            sslCa: self::env('TLS_CA'),
        ));

        self::assertNotSame('', self::mysqlCipher($db));
        $db->close();
    }

    #[DataProvider('mysqlDrivers')]
    public function test_verify_full_fails_closed_against_the_wrong_ca_on_mysql(string $driver): void
    {
        $db = self::mysqlClient($driver, new ConnectionOptions(
            sslMode: 'verify-full',
            sslCa: self::env('TLS_WRONG_CA'),
        ));

        $this->expectException(ConnectionException::class);
        $db->query('SELECT 1');
    }

    #[DataProvider('pgsqlDrivers')]
    public function test_verify_full_accepts_the_real_ca_on_postgres(string $driver): void
    {
        $db = self::pgsqlClient($driver, new ConnectionOptions(
            sslMode: 'verify-full',
            sslCa: self::env('TLS_CA'),
        ));

        $row = $db->query('SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid()')->fetchRow();
        self::assertNotNull($row);
        self::assertTrue((bool) $row['ssl'], 'pg_stat_ssl must report TLS for this backend');
        $db->close();
    }

    #[DataProvider('pgsqlDrivers')]
    public function test_verify_full_fails_closed_against_the_wrong_ca_on_postgres(string $driver): void
    {
        $db = self::pgsqlClient($driver, new ConnectionOptions(
            sslMode: 'verify-full',
            sslCa: self::env('TLS_WRONG_CA'),
        ));

        $this->expectException(ConnectionException::class);
        $db->query('SELECT 1');
    }

    /**
     * Mutual TLS: the server authenticates the client's certificate too
     * (MySQL's REQUIRE X509, Postgres's clientcert=verify-ca). Gated on
     * TLS_CLIENT_CERT so the rest of this file still runs where only
     * server-side TLS is configured.
     */
    #[DataProvider('mysqlDrivers')]
    public function test_mutual_tls_authenticates_the_client_on_mysql(string $driver): void
    {
        $db = self::mysqlClient($driver, self::mutualTlsOptions(), mutual: true);

        self::assertSame(1, $db->query('SELECT 1 AS ok')->fetchRow()['ok']);
        $db->close();
    }

    #[DataProvider('mysqlDrivers')]
    public function test_a_client_without_a_certificate_is_refused_by_mysql(string $driver): void
    {
        self::requireClientCert();

        // Server-side TLS only: correct certificate chain, no client
        // identity — the REQUIRE X509 user must reject it.
        $db = self::mysqlClient($driver, new ConnectionOptions(
            sslMode: 'verify-full',
            sslCa: self::env('TLS_CA'),
        ), mutual: true);

        $this->expectException(ConnectionException::class);
        $db->query('SELECT 1');
    }

    #[DataProvider('pgsqlDrivers')]
    public function test_mutual_tls_authenticates_the_client_on_postgres(string $driver): void
    {
        $db = self::pgsqlClient($driver, self::mutualTlsOptions(), mutual: true);

        self::assertSame(1, $db->query('SELECT 1 AS ok')->fetchRow()['ok']);
        $db->close();
    }

    #[DataProvider('pgsqlDrivers')]
    public function test_a_client_without_a_certificate_is_refused_by_postgres(string $driver): void
    {
        self::requireClientCert();

        $db = self::pgsqlClient($driver, new ConnectionOptions(
            sslMode: 'verify-full',
            sslCa: self::env('TLS_CA'),
        ), mutual: true);

        $this->expectException(ConnectionException::class);
        $db->query('SELECT 1');
    }

    /**
     * Mutual TLS is independent of *server* verification: the client
     * certificate is presented during any handshake, so it works under
     * `require` (no CA, no server verification) too.
     */
    #[DataProvider('mysqlDrivers')]
    public function test_mutual_tls_works_under_require_on_mysql(string $driver): void
    {
        $db = self::mysqlClient($driver, self::requireMutualTlsOptions(), mutual: true);

        self::assertSame(1, $db->query('SELECT 1 AS ok')->fetchRow()['ok']);
        $db->close();
    }

    #[DataProvider('pgsqlDrivers')]
    public function test_mutual_tls_works_under_require_on_postgres(string $driver): void
    {
        $db = self::pgsqlClient($driver, self::requireMutualTlsOptions(), mutual: true);

        self::assertSame(1, $db->query('SELECT 1 AS ok')->fetchRow()['ok']);
        $db->close();
    }

    private static function requireMutualTlsOptions(): ConnectionOptions
    {
        return new ConnectionOptions(
            sslMode: 'require',
            sslCert: self::requireClientCert(),
            sslKey: self::env('TLS_CLIENT_KEY'),
        );
    }

    private static function mutualTlsOptions(): ConnectionOptions
    {
        return new ConnectionOptions(
            sslMode: 'verify-full',
            sslCa: self::env('TLS_CA'),
            sslCert: self::requireClientCert(),
            sslKey: self::env('TLS_CLIENT_KEY'),
        );
    }

    private static function requireClientCert(): string
    {
        $cert = \getenv('TLS_CLIENT_CERT');

        if ($cert === false) {
            self::markTestSkipped('TLS_CLIENT_CERT is not set — mutual-TLS tests are environment-gated.');
        }

        return $cert;
    }

    private static function mysqlClient(string $driver, ConnectionOptions $options, bool $mutual = false): SqlLink
    {
        $host = \getenv('MYSQL_TLS_HOST');

        if ($host === false) {
            self::markTestSkipped('MYSQL_TLS_HOST is not set — TLS tests are environment-gated.');
        }

        $args = [
            $host,
            $mutual ? (\getenv('MYSQL_MTLS_USER') ?: 'mtlsuser') : (\getenv('MYSQL_TLS_USER') ?: 'testuser'),
            \getenv('MYSQL_TLS_PASSWORD') ?: 'testpass',
            \getenv('MYSQL_TLS_DATABASE') ?: 'testdb',
            (int) (\getenv('MYSQL_TLS_PORT') ?: 3306),
            $options,
        ];

        return $driver === 'mysqli-async' ? new MysqliAsyncClient(...$args) : new PdoMysqlClient(...$args);
    }

    private static function pgsqlClient(string $driver, ConnectionOptions $options, bool $mutual = false): SqlLink
    {
        $host = \getenv('POSTGRES_TLS_HOST');

        if ($host === false) {
            self::markTestSkipped('POSTGRES_TLS_HOST is not set — TLS tests are environment-gated.');
        }

        $args = [
            $host,
            $mutual ? (\getenv('POSTGRES_MTLS_USER') ?: 'mtlsuser') : (\getenv('POSTGRES_TLS_USER') ?: 'testuser'),
            \getenv('POSTGRES_TLS_PASSWORD') ?: 'testpass',
            \getenv('POSTGRES_TLS_DATABASE') ?: 'testdb',
            (int) (\getenv('POSTGRES_TLS_PORT') ?: 5432),
            $options,
        ];

        return $driver === 'pgsql-async' ? new PgsqlAsyncClient(...$args) : new PdoPgsqlClient(...$args);
    }

    private static function mysqlCipher(SqlLink $db): string
    {
        $row = $db->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetchRow();

        return $row === null ? '' : (string) $row['Value'];
    }

    private static function env(string $name): string
    {
        $value = \getenv($name);

        if ($value === false) {
            self::markTestSkipped("{$name} is not set — TLS tests are environment-gated.");
        }

        return $value;
    }
}
