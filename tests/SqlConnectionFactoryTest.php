<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use Amp\Mysql\MysqlConnectionPool;
use Amp\Postgres\PostgresConnectionPool;
use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\Config\Exception\MissingConfigException;
use Kinetis\Persistence\SqlConnectionFactory;
use PHPUnit\Framework\TestCase;

final class SqlConnectionFactoryTest extends TestCase
{
    public function test_builds_a_mysql_pool_from_the_default_connections_keys(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => 'db.internal',
            'DB_NAME' => 'app',
            'DB_USER' => 'app',
            'DB_PASSWORD' => 'secret',
        ]);

        self::assertInstanceOf(MysqlConnectionPool::class, SqlConnectionFactory::fromConfig($config));
    }

    public function test_builds_a_postgres_pool_from_the_default_connections_keys(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => 'db.internal',
            'DB_NAME' => 'app',
            'DB_USER' => 'app',
            'DB_PASSWORD' => 'secret',
        ]);

        self::assertInstanceOf(PostgresConnectionPool::class, SqlConnectionFactory::fromConfig($config));
    }

    public function test_a_named_connection_reads_its_own_keys_not_the_defaults(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => 'default.internal',
            'DB_PASSWORD' => 'default-secret',
            'DB_DB2_CONNECTION' => 'pgsql',
            'DB_DB2_HOST' => 'db2.internal',
            'DB_DB2_PASSWORD' => 'db2-secret',
        ]);

        self::assertInstanceOf(MysqlConnectionPool::class, SqlConnectionFactory::fromConfig($config));
        self::assertInstanceOf(PostgresConnectionPool::class, SqlConnectionFactory::fromConfig($config, 'db2'));
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

    public function test_db_options_are_appended_to_the_mysql_connection_string(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => 'db.internal',
            'DB_PASSWORD' => 'secret',
            'DB_OPTIONS' => 'charset=latin1 compress=on',
        ]);

        $pool = SqlConnectionFactory::fromConfig($config);
        self::assertInstanceOf(MysqlConnectionPool::class, $pool);
        self::assertSame('latin1', $pool->getConfig()->getCharset());
        self::assertTrue($pool->getConfig()->isCompressionEnabled());
    }

    public function test_db_options_are_appended_to_the_postgres_connection_string(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => 'db.internal',
            'DB_PASSWORD' => 'secret',
            'DB_OPTIONS' => 'sslmode=require applicationName=tfbench',
        ]);

        $pool = SqlConnectionFactory::fromConfig($config);
        self::assertInstanceOf(PostgresConnectionPool::class, $pool);
        self::assertSame('require', $pool->getConfig()->getSslMode());
        self::assertSame('tfbench', $pool->getConfig()->getApplicationName());
    }

    public function test_db_options_are_scoped_per_named_connection(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => 'default.internal',
            'DB_PASSWORD' => 'default-secret',
            'DB_OPTIONS' => 'charset=latin1',
            'DB_DB2_CONNECTION' => 'mysql',
            'DB_DB2_HOST' => 'db2.internal',
            'DB_DB2_PASSWORD' => 'db2-secret',
            'DB_DB2_OPTIONS' => 'charset=ascii',
        ]);

        $default = SqlConnectionFactory::fromConfig($config);
        $named = SqlConnectionFactory::fromConfig($config, 'db2');
        self::assertInstanceOf(MysqlConnectionPool::class, $default);
        self::assertInstanceOf(MysqlConnectionPool::class, $named);
        self::assertSame('latin1', $default->getConfig()->getCharset());
        self::assertSame('ascii', $named->getConfig()->getCharset());
    }

    public function test_db_options_default_to_absent_leaving_amphps_own_defaults_in_place(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => 'db.internal',
            'DB_PASSWORD' => 'secret',
        ]);

        $pool = SqlConnectionFactory::fromConfig($config);
        self::assertInstanceOf(MysqlConnectionPool::class, $pool);
        self::assertSame('utf8mb4', $pool->getConfig()->getCharset());
    }

    public function test_pool_options_are_passed_through_for_mysql(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => 'db.internal',
            'DB_PASSWORD' => 'secret',
        ]);

        $pool = SqlConnectionFactory::fromConfig($config, poolOptions: ['maxConnections' => 256, 'idleTimeout' => 30]);
        self::assertInstanceOf(MysqlConnectionPool::class, $pool);
        self::assertSame(256, $pool->getConnectionLimit());
        self::assertSame(30, $pool->getIdleTimeout());
    }

    public function test_pool_options_are_passed_through_for_postgres(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => 'db.internal',
            'DB_PASSWORD' => 'secret',
        ]);

        $pool = SqlConnectionFactory::fromConfig($config, poolOptions: ['maxConnections' => 128]);
        self::assertInstanceOf(PostgresConnectionPool::class, $pool);
        self::assertSame(128, $pool->getConnectionLimit());
    }

    public function test_pool_options_default_to_amphps_own_default_pool_size(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => 'db.internal',
            'DB_PASSWORD' => 'secret',
        ]);

        $pool = SqlConnectionFactory::fromConfig($config);
        self::assertInstanceOf(MysqlConnectionPool::class, $pool);
        self::assertSame(100, $pool->getConnectionLimit());
    }

    public function test_a_pool_option_valid_only_for_the_other_dialect_throws(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => 'db.internal',
            'DB_PASSWORD' => 'secret',
        ]);

        // resetConnections exists on PostgresConnectionPool's own constructor,
        // not MysqlConnectionPool's — PHP's own named-argument enforcement
        // rejects it directly, no Kinetis-specific validation involved.
        $this->expectException(\Error::class);
        SqlConnectionFactory::fromConfig($config, poolOptions: ['resetConnections' => false]);
    }
}
