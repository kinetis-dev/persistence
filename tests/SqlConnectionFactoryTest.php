<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\Config\Exception\InvalidConfigValueException;
use Kinetis\Config\Exception\MissingConfigException;
use Kinetis\Persistence\ConnectionOptions;
use Kinetis\Persistence\Driver\MysqliAsyncClient;
use Kinetis\Persistence\Driver\PdoMysqlClient;
use Kinetis\Persistence\Driver\PdoPgsqlClient;
use Kinetis\Persistence\Driver\PgsqlAsyncClient;
use Kinetis\Persistence\SqlConnectionFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlConnectionFactoryTest extends TestCase
{
    public function test_auto_driver_selects_pdo_outside_a_persistent_runtime(): void
    {
        // The test process is not a FrankenPHP worker, so 'auto' must fall back to PDO.
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_PASSWORD' => 'secret',
        ]);

        self::assertInstanceOf(PdoMysqlClient::class, SqlConnectionFactory::fromConfig($config));
    }

    public function test_auto_driver_selects_native_under_road_runner(): void
    {
        // RR_MODE=http is the same signal RuntimeDetector::detect() uses
        // to pick RoadRunnerAdapter — 'auto' must treat it as a
        // persistent runtime exactly like a real FrankenPHP worker.
        $original = getenv('RR_MODE');
        putenv('RR_MODE=http');

        try {
            $config = new Config([
                'DB_CONNECTION' => 'mysql',
                'DB_PASSWORD' => 'secret',
            ]);

            self::assertInstanceOf(MysqliAsyncClient::class, SqlConnectionFactory::fromConfig($config));
        } finally {
            putenv($original === false ? 'RR_MODE' : "RR_MODE={$original}");
        }
    }

    public function test_native_driver_builds_the_mysqli_async_client(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 'secret',
        ]);

        self::assertInstanceOf(MysqliAsyncClient::class, SqlConnectionFactory::fromConfig($config));
    }

    public function test_native_driver_builds_the_pgsql_async_client(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'pgsql',
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 'secret',
        ]);

        self::assertInstanceOf(PgsqlAsyncClient::class, SqlConnectionFactory::fromConfig($config));
    }

    public function test_pdo_driver_builds_the_pdo_pgsql_client(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'pgsql',
            'DB_DRIVER' => 'pdo',
            'DB_PASSWORD' => 'secret',
        ]);

        self::assertInstanceOf(PdoPgsqlClient::class, SqlConnectionFactory::fromConfig($config));
    }

    public function test_driver_selection_is_scoped_per_named_connection(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_DRIVER' => 'pdo',
            'DB_PASSWORD' => 'secret',
            'DB_DB2_CONNECTION' => 'mysql',
            'DB_DB2_DRIVER' => 'native',
            'DB_DB2_PASSWORD' => 'secret',
        ]);

        self::assertInstanceOf(PdoMysqlClient::class, SqlConnectionFactory::fromConfig($config));
        self::assertInstanceOf(MysqliAsyncClient::class, SqlConnectionFactory::fromConfig($config, 'db2'));
    }

    public function test_throws_a_clear_error_when_the_dialect_is_missing_for_the_default_connection(): void
    {
        $config = new Config(['DB_PASSWORD' => 'secret']);

        $this->expectException(MissingConfigException::class);
        $this->expectExceptionMessage('DB_CONNECTION');
        SqlConnectionFactory::fromConfig($config);
    }

    public function test_throws_a_clear_error_naming_the_named_connections_own_key_when_the_dialect_is_missing(): void
    {
        $config = new Config(['DB_DB2_PASSWORD' => 'secret']);

        $this->expectException(MissingConfigException::class);
        $this->expectExceptionMessage('DB_DB2_CONNECTION');
        SqlConnectionFactory::fromConfig($config, 'db2');
    }

    public function test_throws_when_the_dialect_is_neither_mysql_nor_pgsql(): void
    {
        $config = new Config(['DB_CONNECTION' => 'sqlite', 'DB_PASSWORD' => 'secret']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DB_CONNECTION must be "mysql" or "pgsql".');
        SqlConnectionFactory::fromConfig($config);
    }

    public function test_throws_when_the_driver_is_unknown(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_DRIVER' => 'odbc',
            'DB_PASSWORD' => 'secret',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DB_DRIVER must be "auto", "native", or "pdo", got "odbc".');
        SqlConnectionFactory::fromConfig($config);
    }

    public function test_an_option_the_selected_driver_cannot_honor_fails_loudly(): void
    {
        // applicationName is a Postgres concept; the mysqli driver must
        // reject it at construction, never silently ignore it.
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 'secret',
            'DB_APP_NAME' => 'myapp',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('applicationName');
        SqlConnectionFactory::fromConfig($config);
    }

    public function test_connection_options_reject_a_non_identifier_charset(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('charset');
        new ConnectionOptions(charset: "utf8mb4'; DROP TABLE x; --");
    }

    public function test_connection_options_reject_a_non_positive_max_connections(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maxConnections');
        new ConnectionOptions(maxConnections: 0);
    }
    private static function property(object $object, string $name): mixed
    {
        return new \ReflectionProperty($object, $name)->getValue($object);
    }

    public function test_db_port_wins_and_dialect_defaults_apply_when_unset(): void
    {
        $withPort = SqlConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'mysql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's', 'DB_PORT' => '13306',
        ]));
        self::assertSame(13306, self::property($withPort, 'port'));

        $mysqlDefault = SqlConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'mysql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's',
        ]));
        self::assertSame(3306, self::property($mysqlDefault, 'port'));

        $pgDefault = SqlConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'pgsql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's',
        ]));
        self::assertSame(5432, self::property($pgDefault, 'port'));
    }

    public function test_discrete_option_keys_reach_the_driver(): void
    {
        $client = SqlConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'pgsql',
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 's',
            'DB_CHARSET' => 'UTF8',
            'DB_SSLMODE' => 'require',
            'DB_CONNECT_TIMEOUT' => '7',
            'DB_APP_NAME' => 'myapp',
        ]));

        $options = self::property($client, 'options');
        self::assertInstanceOf(ConnectionOptions::class, $options);
        self::assertSame('UTF8', $options->charset);
        self::assertSame('require', $options->sslMode);
        self::assertSame(7, $options->connectTimeout);
        self::assertSame('myapp', $options->applicationName);
    }

    public function test_compression_truthy_spellings_parse_to_true(): void
    {
        foreach (['1', 'true', 'on', 'yes'] as $spelling) {
            $client = SqlConnectionFactory::fromConfig(new Config([
                'DB_CONNECTION' => 'mysql',
                'DB_DRIVER' => 'native',
                'DB_PASSWORD' => 's',
                'DB_COMPRESSION' => $spelling,
            ]));

            $options = self::property($client, 'options');
            self::assertInstanceOf(ConnectionOptions::class, $options);
            self::assertTrue($options->compression, "spelling: {$spelling}");
        }
    }

    public function test_compression_truthy_spellings_are_matched_case_insensitively(): void
    {
        foreach (['TRUE', 'ON', 'YES', 'True', 'On'] as $spelling) {
            $client = SqlConnectionFactory::fromConfig(new Config([
                'DB_CONNECTION' => 'mysql',
                'DB_DRIVER' => 'native',
                'DB_PASSWORD' => 's',
                'DB_COMPRESSION' => $spelling,
            ]));

            $options = self::property($client, 'options');
            self::assertInstanceOf(ConnectionOptions::class, $options);
            self::assertTrue($options->compression, "spelling: {$spelling}");
        }
    }

    public function test_max_connections_pool_option_reaches_the_driver(): void
    {
        $client = SqlConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'mysql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's',
        ]), poolOptions: ['maxConnections' => 3]);

        $options = self::property($client, 'options');
        self::assertInstanceOf(ConnectionOptions::class, $options);
        self::assertSame(3, $options->maxConnections);
    }

    /**
     * @return list<array{mixed}>
     */
    public static function nonIntPoolOptionValues(): array
    {
        return [
            'a string' => ['garbage'],
            'a float' => [1.9],
            'an object' => [new \stdClass()],
            'a bool' => [true],
        ];
    }

    #[DataProvider('nonIntPoolOptionValues')]
    public function test_a_non_int_max_connections_pool_option_is_a_clear_configuration_error(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("\$poolOptions['maxConnections'] must be an int, got");

        SqlConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'mysql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's',
        ]), poolOptions: ['maxConnections' => $value]);
    }

    #[DataProvider('nonIntPoolOptionValues')]
    public function test_a_non_int_warm_connections_pool_option_is_a_clear_configuration_error(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("\$poolOptions['warmConnections'] must be an int, got");

        SqlConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'mysql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's',
        ]), poolOptions: ['warmConnections' => $value]);
    }

    public function test_db_max_connections_env_key_sizes_the_pool(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 'secret',
            'DB_MAX_CONNECTIONS' => '12',
        ]);

        self::assertSame(12, self::maxConnectionsOf(SqlConnectionFactory::fromConfig($config)));
    }

    public function test_an_explicit_pool_option_wins_over_the_env_key(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 'secret',
            'DB_MAX_CONNECTIONS' => '12',
        ]);

        $client = SqlConnectionFactory::fromConfig($config, poolOptions: ['maxConnections' => 3]);

        self::assertSame(3, self::maxConnectionsOf($client));
    }

    public function test_pool_width_defaults_when_neither_env_nor_pool_option_is_set(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 'secret',
        ]);

        self::assertSame(8, self::maxConnectionsOf(SqlConnectionFactory::fromConfig($config)));
    }

    public function test_db_max_connections_is_scoped_per_named_connection(): void
    {
        $config = new Config([
            'DB_ANALYTICS_CONNECTION' => 'mysql',
            'DB_ANALYTICS_DRIVER' => 'native',
            'DB_ANALYTICS_PASSWORD' => 'secret',
            'DB_ANALYTICS_MAX_CONNECTIONS' => '5',
        ]);

        self::assertSame(5, self::maxConnectionsOf(SqlConnectionFactory::fromConfig($config, 'analytics')));
    }

    public function test_a_non_numeric_db_max_connections_throws(): void
    {
        $this->expectException(InvalidConfigValueException::class);

        SqlConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'mysql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's',
            'DB_MAX_CONNECTIONS' => 'many',
        ]));
    }

    public function test_a_non_numeric_db_port_throws(): void
    {
        $this->expectException(InvalidConfigValueException::class);

        SqlConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'mysql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's',
            'DB_PORT' => 'not-a-port',
        ]));
    }

    public function test_a_non_numeric_db_warm_connections_throws(): void
    {
        $this->expectException(InvalidConfigValueException::class);

        SqlConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'mysql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's',
            'DB_WARM_CONNECTIONS' => 'lots',
        ]));
    }

    /**
     * @return list<array{string}>
     */
    public static function outOfRangePortCases(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-1'],
            'beyond 65535' => ['65536'],
        ];
    }

    #[DataProvider('outOfRangePortCases')]
    public function test_a_db_port_outside_the_valid_tcp_range_throws(string $port): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DB_PORT must be a valid TCP port');

        SqlConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'mysql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's',
            'DB_PORT' => $port,
        ]));
    }

    public function test_a_negative_db_warm_connections_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DB_WARM_CONNECTIONS must not be negative');

        SqlConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'mysql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's',
            'DB_WARM_CONNECTIONS' => '-1',
        ]));
    }

    public function test_a_non_numeric_db_connect_timeout_throws(): void
    {
        $this->expectException(InvalidConfigValueException::class);

        SqlConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'mysql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's',
            'DB_CONNECT_TIMEOUT' => 'slow',
        ]));
    }

    #[\PHPUnit\Framework\Attributes\RequiresPhpExtension('mysqli')]
    public function test_warm_connections_pool_option_connects_at_construction(): void
    {
        // Port 1 refuses immediately: reaching the network at all is
        // what proves warming fires inside fromConfig() rather than
        // deferring to the first query.
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 'secret',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '1',
        ]);

        $this->expectException(\Kinetis\Persistence\Exception\ConnectionException::class);

        SqlConnectionFactory::fromConfig($config, poolOptions: ['warmConnections' => 1]);
    }

    #[\PHPUnit\Framework\Attributes\RequiresPhpExtension('mysqli')]
    public function test_db_warm_connections_env_key_triggers_warming(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 'secret',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '1',
            'DB_WARM_CONNECTIONS' => '1',
        ]);

        $this->expectException(\Kinetis\Persistence\Exception\ConnectionException::class);

        SqlConnectionFactory::fromConfig($config);
    }

    public function test_no_connection_is_opened_without_a_warming_request(): void
    {
        // Same unreachable endpoint as the warming tests: constructing
        // without warmConnections must not touch the network.
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 'secret',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '1',
        ]);

        self::assertInstanceOf(MysqliAsyncClient::class, SqlConnectionFactory::fromConfig($config));
    }

    public function test_an_explicit_warm_pool_option_wins_over_the_warm_env_key(): void
    {
        // The env key asks for warming against an unreachable server;
        // the explicit zero must override it, so construction succeeds.
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 'secret',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '1',
            'DB_WARM_CONNECTIONS' => '1',
        ]);

        $client = SqlConnectionFactory::fromConfig($config, poolOptions: ['warmConnections' => 0]);

        self::assertInstanceOf(MysqliAsyncClient::class, $client);
    }

    public function test_warm_up_on_a_closed_client_throws(): void
    {
        $client = new MysqliAsyncClient('127.0.0.1', 'u', 'p', 'db', 1, new ConnectionOptions());
        $client->close();

        $this->expectException(\Kinetis\Persistence\Exception\ConnectionException::class);

        $client->warmUp(1);
    }

    public function test_db_ssl_ca_reaches_the_driver(): void
    {
        $direct = SqlConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'pgsql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's',
            'DB_SSLMODE' => 'verify-full', 'DB_SSL_CA' => '/certs/ca.pem',
        ]));

        $options = self::property($direct, 'options');
        self::assertInstanceOf(ConnectionOptions::class, $options);
        self::assertSame('/certs/ca.pem', $options->sslCa);
        self::assertSame('', $options->extraConnectionString);
    }

    public function test_mysql_drivers_construct_with_a_verifying_tls_profile(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'mysql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's',
            'DB_SSLMODE' => 'verify-ca', 'DB_SSL_CA' => '/certs/ca.pem',
        ]);

        self::assertInstanceOf(MysqliAsyncClient::class, SqlConnectionFactory::fromConfig($config));
    }

    public function test_mysql_drivers_reject_a_libpq_only_ssl_mode(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'mysql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's',
            'DB_SSLMODE' => 'prefer',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no opportunistic TLS');
        SqlConnectionFactory::fromConfig($config);
    }

    private static function maxConnectionsOf(object $client): int
    {
        $property = new \ReflectionProperty($client, 'options');

        /** @var ConnectionOptions $options */
        $options = $property->getValue($client);

        return $options->maxConnections;
    }
}
