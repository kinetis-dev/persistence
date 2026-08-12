<?php

declare(strict_types=1);

namespace Kinetis\Persistence;

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use InvalidArgumentException;
use Kinetis\Config\Config;

/**
 * Builds a MysqlConnectionPool/PostgresConnectionPool from Config —
 * previously duplicated inline in both kinetis/migrations' bin/migrate and
 * kinetis/queue's bin/queue, extracted here once both needed the identical
 * logic a second time.
 *
 * $connection selects a named connection via Config::scopedKey() —
 * 'default' (the default) reads the plain DB_* keys unchanged; any other
 * name reads DB_{NAME}_* instead. A named connection is never autowired by
 * type; retrieve it from the container explicitly (or construct it
 * directly) wherever it's needed.
 */
final class SqlConnectionFactory
{
    public static function fromConfig(Config $config, string $connection = 'default'): MysqlConnectionPool|PostgresConnectionPool
    {
        $connectionString = 'host=' . $config->string(Config::scopedKey('DB_HOST', $connection), '127.0.0.1')
            . ' dbname=' . $config->string(Config::scopedKey('DB_NAME', $connection), 'app')
            . ' user=' . $config->string(Config::scopedKey('DB_USER', $connection), 'app')
            . ' password=' . $config->required(Config::scopedKey('DB_PASSWORD', $connection));

        $port = $config->get(Config::scopedKey('DB_PORT', $connection));

        if ($port !== null) {
            $connectionString .= " port={$port}";
        }

        $dialectKey = Config::scopedKey('DB_CONNECTION', $connection);

        return match ($config->required($dialectKey)) {
            'mysql' => new MysqlConnectionPool(MysqlConfig::fromString($connectionString)),
            'pgsql' => new PostgresConnectionPool(PostgresConfig::fromString($connectionString)),
            default => throw new InvalidArgumentException("{$dialectKey} must be \"mysql\" or \"pgsql\"."),
        };
    }
}
