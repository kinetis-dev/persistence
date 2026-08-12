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
}
