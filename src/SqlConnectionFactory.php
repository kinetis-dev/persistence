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
 *
 * Two independent, deliberately separate extension points, since they
 * apply at two different layers of amphp's own API and can't be merged
 * into one mechanism:
 *
 * - `DB_OPTIONS` (also connection-scoped via Config::scopedKey()) is
 *   appended verbatim to the connection string handed to
 *   MysqlConfig::fromString()/PostgresConfig::fromString() — e.g.
 *   "charset=latin1 collate=latin1_swedish_ci" for MySQL, or
 *   "sslmode=require application_name=myapp" for Postgres. This is
 *   genuinely dialect-specific: each Config class's own KEY_MAP only
 *   recognizes its own keys (confirmed by reading both directly) and
 *   silently ignores anything it doesn't — so a key meant for the other
 *   dialect just does nothing rather than erroring, the caller's own
 *   responsibility to get right for whichever dialect they're actually
 *   using.
 * - $poolOptions is spread as named arguments into whichever pool
 *   constructor gets used (`maxConnections`, `idleTimeout`,
 *   `transactionIsolation`, `connector`, and — Postgres only —
 *   `resetConnections`; confirmed by reading both constructors directly,
 *   they genuinely diverge). This is a pool-level concern
 *   (MysqlConnectionPool/PostgresConnectionPool's own constructor, never
 *   part of the connection string) — DB_OPTIONS can't reach it no matter
 *   what's put in the string, since neither Config class's own
 *   `fromString()` reads a pool-sizing key at all. A key valid for one
 *   dialect but not the other throws PHP's own "Unknown named parameter"
 *   TypeError at construction — the same "let PHP's own argument
 *   enforcement reject what's invalid" approach already used for `Query`'s
 *   MysqlLink|PostgresLink typing elsewhere in this codebase, not a new
 *   exception type.
 */
final class SqlConnectionFactory
{
    /**
     * @param array<string, mixed> $poolOptions Deliberately loosely typed —
     *     see the class docblock above. A single precise shape can't express
     *     "these keys only valid for mysql, these only for pgsql" without the
     *     caller already knowing the dialect ahead of the fromConfig() call
     *     that resolves it.
     */
    public static function fromConfig(Config $config, string $connection = 'default', array $poolOptions = []): MysqlConnectionPool|PostgresConnectionPool
    {
        $connectionString = 'host=' . $config->string(Config::scopedKey('DB_HOST', $connection), '127.0.0.1')
            . ' dbname=' . $config->string(Config::scopedKey('DB_NAME', $connection), 'app')
            . ' user=' . $config->string(Config::scopedKey('DB_USER', $connection), 'app')
            . ' password=' . $config->required(Config::scopedKey('DB_PASSWORD', $connection));

        $port = $config->get(Config::scopedKey('DB_PORT', $connection));

        if ($port !== null) {
            $connectionString .= " port={$port}";
        }

        $options = $config->get(Config::scopedKey('DB_OPTIONS', $connection));

        if ($options !== null) {
            $connectionString .= " {$options}";
        }

        $dialectKey = Config::scopedKey('DB_CONNECTION', $connection);

        return match ($config->required($dialectKey)) {
            'mysql' => new MysqlConnectionPool(MysqlConfig::fromString($connectionString), ...$poolOptions),
            'pgsql' => new PostgresConnectionPool(PostgresConfig::fromString($connectionString), ...$poolOptions),
            default => throw new InvalidArgumentException("{$dialectKey} must be \"mysql\" or \"pgsql\"."),
        };
    }
}
