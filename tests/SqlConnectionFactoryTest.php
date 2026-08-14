<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\Config\Exception\MissingConfigException;
use Kinetis\Persistence\ConnectionOptions;
use Kinetis\Persistence\Driver\MysqliAsyncClient;
use Kinetis\Persistence\Driver\PdoMysqlClient;
use Kinetis\Persistence\Driver\PdoPgsqlClient;
use Kinetis\Persistence\Driver\PgsqlAsyncClient;
use Kinetis\Persistence\SqlConnectionFactory;
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

    public function test_legacy_db_options_keys_with_canonical_equivalents_are_translated(): void
    {
        // charset=latin1 has a canonical equivalent; it must not land in
        // extraConnectionString (which the mysqli driver would reject),
        // and must therefore construct fine on a driver with no free-form
        // string support.
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 'secret',
            'DB_OPTIONS' => 'charset=latin1 compress=on',
        ]);

        self::assertInstanceOf(MysqliAsyncClient::class, SqlConnectionFactory::fromConfig($config));
    }

    public function test_untranslatable_db_options_keys_are_rejected_by_drivers_without_free_form_strings(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 'secret',
            'DB_OPTIONS' => 'local-infile=1',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('extraConnectionString');
        SqlConnectionFactory::fromConfig($config);
    }

    public function test_untranslatable_db_options_keys_pass_through_to_libpq_backed_drivers(): void
    {
        // libpq accepts free-form connection-string keys and validates
        // them itself at connect time — construction must succeed.
        $config = new Config([
            'DB_CONNECTION' => 'pgsql',
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 'secret',
            'DB_OPTIONS' => 'target_session_attrs=read-write',
        ]);

        self::assertInstanceOf(PgsqlAsyncClient::class, SqlConnectionFactory::fromConfig($config));
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

    public function test_a_discrete_key_wins_over_the_db_options_spelling(): void
    {
        $client = SqlConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 's',
            'DB_CHARSET' => 'utf8mb4',
            'DB_OPTIONS' => 'charset=latin1 compress=on',
        ]));

        $options = self::property($client, 'options');
        self::assertInstanceOf(ConnectionOptions::class, $options);
        self::assertSame('utf8mb4', $options->charset);
        self::assertTrue($options->compression);
    }

    public function test_db_options_values_may_contain_equals_signs(): void
    {
        // explode('=', ..., 2): only the first "=" splits key from value.
        $client = SqlConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'pgsql',
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 's',
            'DB_OPTIONS' => 'application_name=a=b',
        ]));

        $options = self::property($client, 'options');
        self::assertInstanceOf(ConnectionOptions::class, $options);
        self::assertSame('a=b', $options->applicationName);
    }

    public function test_a_bare_db_options_key_without_a_value_parses_as_an_empty_value(): void
    {
        // "compress" with no "=" means an empty value, which is falsy —
        // not a parse error, and not silently treated as unknown.
        $client = SqlConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 's',
            'DB_OPTIONS' => 'compress',
        ]));

        $options = self::property($client, 'options');
        self::assertInstanceOf(ConnectionOptions::class, $options);
        self::assertFalse($options->compression);
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

    public function test_max_connections_pool_option_reaches_the_driver(): void
    {
        $client = SqlConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'mysql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's',
        ]), poolOptions: ['maxConnections' => 3]);

        $options = self::property($client, 'options');
        self::assertInstanceOf(ConnectionOptions::class, $options);
        self::assertSame(3, $options->maxConnections);
    }
}
