<?php

declare(strict_types=1);

namespace Kinetis\Persistence;

use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Driver\MysqliAsyncClient;
use Kinetis\Persistence\Driver\PdoMysqlClient;
use Kinetis\Persistence\Driver\PdoPgsqlClient;
use Kinetis\Persistence\Driver\PgsqlAsyncClient;

/**
 * Builds a database client from Config, choosing the driver that fits
 * the runtime — every client implements the Kinetis-owned
 * {@see MysqlLink}/{@see PostgresLink} contracts, so TransactionGuard,
 * the query builder, and application code are driver-agnostic.
 *
 * Driver selection (`DB_DRIVER`, connection-scoped like every other
 * DB_* key):
 *
 * - `auto` (the default): a persistent runtime (FrankenPHP worker mode)
 *   gets the native async driver; a boot-and-die runtime (PHP-FPM) gets
 *   PDO. This split is measured, not aesthetic: under boot-and-die,
 *   per-request handshakes and per-query client CPU dominate, and an
 *   async client's overlap buys nothing a blocking driver doesn't
 *   already deliver — while a persistent worker amortizes connections
 *   across requests and genuinely benefits from native async fan-out.
 * - `native`: mysqli's MYSQLI_ASYNC ({@see MysqliAsyncClient}) or
 *   ext-pgsql's pg_send_query ({@see PgsqlAsyncClient}). C-speed wire
 *   protocol, Fiber-suspending, `concurrently()`-compatible.
 * - `pdo`: one blocking PDO connection ({@see PdoMysqlClient}/
 *   {@see PdoPgsqlClient}).
 *
 * Connection options are canonical and driver-neutral — see
 * {@see ConnectionOptions}. They come from discrete, connection-scoped
 * keys (`DB_CHARSET`, `DB_COLLATION`, `DB_SSLMODE`,
 * `DB_CONNECT_TIMEOUT`, `DB_APP_NAME`, `DB_COMPRESSION`), each driver
 * translating them to its native mechanism, and rejecting — loudly, at
 * construction — any option it cannot honor.
 *
 * The legacy `DB_OPTIONS` string is still accepted as a migration path:
 * key=value pairs whose keys have canonical equivalents are translated
 * (a discrete key wins over a DB_OPTIONS spelling of the same option);
 * anything untranslatable is passed through raw only to drivers whose
 * backend natively accepts free-form connection-string keys (libpq),
 * and rejected everywhere else.
 *
 * $connection selects a named connection via Config::scopedKey() —
 * 'default' reads the plain DB_* keys; any other name reads DB_{NAME}_*.
 *
 * $poolOptions['maxConnections'] caps the async drivers' fan-out width
 * (the PDO drivers are a single connection, trivially within any cap).
 */
final class SqlConnectionFactory
{
    /** Legacy DB_OPTIONS keys with canonical equivalents, in every historical spelling. */
    private const array LEGACY_KEY_MAP = [
        'charset' => 'charset',
        'client_encoding' => 'charset',
        'collate' => 'collation',
        'collation' => 'collation',
        'sslmode' => 'sslMode',
        'connect_timeout' => 'connectTimeout',
        'application_name' => 'applicationName',
        'applicationname' => 'applicationName',
        'compress' => 'compression',
        'compression' => 'compression',
    ];

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

        if ($driver !== 'native' && $driver !== 'pdo') {
            throw new InvalidArgumentException(
                Config::scopedKey('DB_DRIVER', $connection) . " must be \"auto\", \"native\", or \"pdo\", got \"{$driver}\".",
            );
        }

        $defaultPort = $dialect === 'mysql' ? 3306 : 5432;
        $portValue = $config->get(Config::scopedKey('DB_PORT', $connection));
        $port = $portValue !== null ? (int) $portValue : $defaultPort;

        $options = self::buildOptions($config, $connection, $poolOptions);

        return match (true) {
            $dialect === 'mysql' && $driver === 'native' => new MysqliAsyncClient($host, $user, $password, $database, $port, $options),
            $dialect === 'mysql' => new PdoMysqlClient($host, $user, $password, $database, $port, $options),
            $driver === 'native' => new PgsqlAsyncClient($host, $user, $password, $database, $port, $options),
            default => new PdoPgsqlClient($host, $user, $password, $database, $port, $options),
        };
    }

    /**
     * @param array<string, mixed> $poolOptions
     */
    private static function buildOptions(Config $config, string $connection, array $poolOptions): ConnectionOptions
    {
        // Legacy DB_OPTIONS: translate what has a canonical equivalent,
        // keep the rest for backends that accept free-form keys.
        $legacy = [];
        $extra = [];
        $legacyString = $config->get(Config::scopedKey('DB_OPTIONS', $connection));

        foreach (\preg_split('/\s+/', $legacyString ?? '', flags: \PREG_SPLIT_NO_EMPTY) ?: [] as $pair) {
            [$key, $value] = \array_pad(\explode('=', $pair, 2), 2, '');
            $canonical = self::LEGACY_KEY_MAP[\strtolower($key)] ?? null;

            if ($canonical !== null) {
                $legacy[$canonical] = $value;
            } else {
                $extra[] = $pair;
            }
        }

        $compression = $config->get(Config::scopedKey('DB_COMPRESSION', $connection))
            ?? $legacy['compression']
            ?? null;

        $connectTimeout = $config->get(Config::scopedKey('DB_CONNECT_TIMEOUT', $connection))
            ?? $legacy['connectTimeout']
            ?? null;

        // An explicit code-level poolOption wins; the connection-scoped
        // env key covers deployments tuning pool width without editing
        // bootstrap code (see docs/performance-tuning.md for sizing).
        $envMaxConnections = $config->get(Config::scopedKey('DB_MAX_CONNECTIONS', $connection));

        /** @var int $maxConnections */
        $maxConnections = $poolOptions['maxConnections']
            ?? ($envMaxConnections !== null ? (int) $envMaxConnections : 8);

        return new ConnectionOptions(
            charset: $config->get(Config::scopedKey('DB_CHARSET', $connection)) ?? $legacy['charset'] ?? null,
            collation: $config->get(Config::scopedKey('DB_COLLATION', $connection)) ?? $legacy['collation'] ?? null,
            sslMode: $config->get(Config::scopedKey('DB_SSLMODE', $connection)) ?? $legacy['sslMode'] ?? null,
            connectTimeout: $connectTimeout !== null ? (int) $connectTimeout : null,
            applicationName: $config->get(Config::scopedKey('DB_APP_NAME', $connection)) ?? $legacy['applicationName'] ?? null,
            compression: $compression !== null ? \in_array(\strtolower((string) $compression), ['1', 'true', 'on', 'yes'], true) : null,
            maxConnections: $maxConnections,
            extraConnectionString: \implode(' ', $extra),
        );
    }
}
