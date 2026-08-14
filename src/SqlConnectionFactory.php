<?php

declare(strict_types=1);

namespace Kinetis\Persistence;

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Mysql\MysqlLink;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use Amp\Postgres\PostgresLink;
use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\Persistence\Driver\MysqliAsyncClient;
use Kinetis\Persistence\Driver\PdoMysqlClient;
use Kinetis\Persistence\Driver\PdoPgsqlClient;
use Kinetis\Persistence\Driver\PgsqlAsyncClient;

/**
 * Builds a database client from Config, choosing the driver that fits
 * the runtime — every client implements amphp's MysqlLink/PostgresLink,
 * so TransactionGuard, the query builder, and application code are
 * driver-agnostic.
 *
 * Driver selection (`DB_DRIVER`, connection-scoped like every other
 * DB_* key):
 *
 * - `auto` (the default): a persistent runtime (FrankenPHP worker mode)
 *   gets the native async driver; a boot-and-die runtime (PHP-FPM) gets
 *   PDO. This split is measured, not aesthetic: per-request handshakes
 *   and per-query CPU dominate under boot-and-die, where an async
 *   client's overlap buys nothing a blocking driver doesn't already
 *   deliver — while a persistent worker amortizes connections across
 *   requests and genuinely benefits from native async fan-out.
 * - `native`: mysqli's MYSQLI_ASYNC ({@see MysqliAsyncClient}) or
 *   ext-pgsql's pg_send_query ({@see PgsqlAsyncClient}). C-speed wire
 *   protocol, Fiber-suspending, `concurrently()`-compatible.
 * - `pdo`: one blocking PDO connection ({@see PdoMysqlClient}/
 *   {@see PdoPgsqlClient}).
 * - `amphp`: the original pure-PHP amphp/mysql/amphp/postgres pools —
 *   still the only driver offering server-side prepared statement
 *   objects (prepare()) and row streaming.
 *
 * $connection selects a named connection via Config::scopedKey() —
 * 'default' reads the plain DB_* keys; any other name reads DB_{NAME}_*.
 *
 * `DB_OPTIONS` and $poolOptions keep their amphp semantics; for the
 * native drivers only $poolOptions['maxConnections'] applies (fan-out
 * width), and for PDO no pool option applies at all — both silently
 * accept and ignore what they can't use, so one bootstrap works
 * unchanged across runtimes.
 */
final class SqlConnectionFactory
{
    /**
     * @param array<string, mixed> $poolOptions
     */
    public static function fromConfig(Config $config, string $connection = 'default', array $poolOptions = []): MysqlLink|PostgresLink
    {
        $host = $config->string(Config::scopedKey('DB_HOST', $connection), '127.0.0.1');
        $database = $config->string(Config::scopedKey('DB_NAME', $connection), 'app');
        $user = $config->string(Config::scopedKey('DB_USER', $connection), 'app');
        $password = $config->required(Config::scopedKey('DB_PASSWORD', $connection));

        $dialectKey = Config::scopedKey('DB_CONNECTION', $connection);
        $dialect = $config->required($dialectKey);

        if ($dialect !== 'mysql' && $dialect !== 'pgsql') {
            throw new InvalidArgumentException("{$dialectKey} must be \"mysql\" or \"pgsql\".");
        }

        $driver = $config->string(Config::scopedKey('DB_DRIVER', $connection), 'auto');

        if ($driver === 'auto') {
            $driver = \function_exists('frankenphp_handle_request') ? 'native' : 'pdo';
        }

        $port = $config->get(Config::scopedKey('DB_PORT', $connection));
        $port = $port !== null ? (int) $port : ($dialect === 'mysql' ? 3306 : 5432);

        /** @var int $maxConnections */
        $maxConnections = $poolOptions['maxConnections'] ?? 8;

        return match ($driver) {
            'native' => $dialect === 'mysql'
                ? new MysqliAsyncClient($host, $user, $password, $database, $port, $maxConnections)
                : new PgsqlAsyncClient($host, $user, $password, $database, $port, $maxConnections),
            'pdo' => $dialect === 'mysql'
                ? new PdoMysqlClient($host, $user, $password, $database, $port)
                : new PdoPgsqlClient($host, $user, $password, $database, $port),
            'amphp' => self::amphpPool($config, $connection, $dialect, $host, $database, $user, $password, $poolOptions),
            default => throw new InvalidArgumentException(
                Config::scopedKey('DB_DRIVER', $connection) . " must be \"auto\", \"native\", \"pdo\", or \"amphp\", got \"{$driver}\".",
            ),
        };
    }

    /**
     * The original amphp pool construction, unchanged: connection string
     * assembly plus DB_OPTIONS plus $poolOptions spread as named
     * arguments into the pool constructor.
     *
     * @param array<string, mixed> $poolOptions
     */
    private static function amphpPool(
        Config $config,
        string $connection,
        string $dialect,
        string $host,
        string $database,
        string $user,
        #[\SensitiveParameter] string $password,
        array $poolOptions,
    ): MysqlConnectionPool|PostgresConnectionPool {
        $connectionString = "host={$host} dbname={$database} user={$user} password={$password}";

        $port = $config->get(Config::scopedKey('DB_PORT', $connection));

        if ($port !== null) {
            $connectionString .= " port={$port}";
        }

        $options = $config->get(Config::scopedKey('DB_OPTIONS', $connection));

        if ($options !== null) {
            $connectionString .= " {$options}";
        }

        return $dialect === 'mysql'
            ? new MysqlConnectionPool(MysqlConfig::fromString($connectionString), ...$poolOptions)
            : new PostgresConnectionPool(PostgresConfig::fromString($connectionString), ...$poolOptions);
    }
}
