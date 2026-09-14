<?php

declare(strict_types=1);

namespace Kinetis\Persistence;

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Contract\SqlInstrumentation;
use Kinetis\Persistence\Driver\MysqliAsyncClient;
use Kinetis\Persistence\Driver\PdoMysqlClient;
use Kinetis\Persistence\Driver\PdoPgsqlClient;
use Kinetis\Persistence\Driver\PgsqlAsyncClient;

/**
 * Builds a database client from a {@see ConnectionDefinition}, choosing
 * the driver that fits the runtime — every client implements the
 * {@see MysqlLink}/{@see PostgresLink} contracts, so TransactionGuard,
 * the query builder, and application code are driver-agnostic.
 *
 * Driver selection ({@see ConnectionDefinition::$driver}):
 *
 * - `auto`: the native async driver when `frankenphp_handle_request()`
 *   exists (FrankenPHP worker mode) or `RR_MODE=http` (RoadRunner), PDO
 *   everywhere else — PHP-FPM and AWS Lambda included (see
 *   docs/persistence.md for what Lambda needs before `native` is the
 *   right choice there). Native is the measured default for the
 *   supported persistent-worker targets, FrankenPHP and RoadRunner; PDO
 *   is the baseline everywhere else because it is the commonly installed
 *   and default-enabled driver. Lambda stays an explicit opt-in: the
 *   deployment has to ship the native extension and budget a pool per
 *   execution environment, and there is no Lambda measurement here to
 *   justify choosing native for it automatically.
 * - `native`: mysqli's MYSQLI_ASYNC ({@see MysqliAsyncClient}) or
 *   ext-pgsql's pg_send_query ({@see PgsqlAsyncClient}). C-speed wire
 *   protocol, Fiber-suspending. The Postgres client also needs
 *   ext-sockets and says so at construction if it is missing.
 * - `pdo`: one blocking PDO connection ({@see PdoMysqlClient}/
 *   {@see PdoPgsqlClient}).
 *
 * Each driver translates the canonical {@see ConnectionOptions} to its
 * native mechanism and rejects — loudly, at construction — any option it
 * cannot honor. {@see ConnectionOptions::$maxConnections} caps the async
 * drivers' fan-out width; the PDO drivers are a single connection.
 *
 * $instrumentation receives the moments every client and transaction
 * reports; without one they report nothing.
 */
final class SqlConnectionFactory
{
    public static function create(
        ConnectionDefinition $definition,
        ?SqlInstrumentation $instrumentation = null,
    ): MysqlLink|PostgresLink {
        $native = match ($definition->driver) {
            'auto' => self::shouldUseNativeDriverByDefault(),
            'native' => true,
            'pdo' => false,
        };

        [$host, $user, $password, $database, $port, $options] = self::arguments($definition);

        return self::warm($definition, match (true) {
            $definition->dialect === 'mysql' && $native => new MysqliAsyncClient($host, $user, $password, $database, $port, $options, $instrumentation),
            $definition->dialect === 'mysql' => new PdoMysqlClient($host, $user, $password, $database, $port, $options, false, $instrumentation),
            $native => new PgsqlAsyncClient($host, $user, $password, $database, $port, $options, $instrumentation),
            default => new PdoPgsqlClient($host, $user, $password, $database, $port, $options, false, $instrumentation),
        });
    }

    /**
     * A client pinned to the first session it opens: PDO whatever the
     * definition's driver says, and closed for good if that session is
     * ever discarded, rather than reconnecting.
     *
     * That is what work living in the session itself needs.
     * `kinetis/migrations` holds a session-scoped advisory lock for a
     * whole run, so a replacement session would be an unlocked one the
     * run kept going on. Everything else wants {@see create()}, where
     * reconnecting is what keeps a long-lived process working.
     */
    public static function singleSession(
        ConnectionDefinition $definition,
        ?SqlInstrumentation $instrumentation = null,
    ): MysqlLink|PostgresLink {
        [$host, $user, $password, $database, $port, $options] = self::arguments($definition);

        return self::warm($definition, $definition->dialect === 'mysql'
            ? new PdoMysqlClient($host, $user, $password, $database, $port, $options, true, $instrumentation)
            : new PdoPgsqlClient($host, $user, $password, $database, $port, $options, true, $instrumentation));
    }

    /**
     * @return array{string, string, string, string, int, ConnectionOptions}
     */
    private static function arguments(ConnectionDefinition $definition): array
    {
        return [
            $definition->host,
            $definition->user,
            $definition->password,
            $definition->database,
            $definition->port,
            $definition->options,
        ];
    }

    /**
     * Warming connects right here, so a wrong definition fails at boot
     * instead of on the first query — and under a persistent worker
     * (FrankenPHP or RoadRunner) the boot-time connect is what keeps
     * mysqli fds numbered below FD_SETSIZE (see
     * MysqliAsyncClient::warmUp()).
     */
    private static function warm(
        ConnectionDefinition $definition,
        MysqliAsyncClient|PdoMysqlClient|PgsqlAsyncClient|PdoPgsqlClient $client,
    ): MysqlLink|PostgresLink {
        if ($definition->warmConnections > 0) {
            $client->warmUp($definition->warmConnections);
        }

        return $client;
    }

    /**
     * The whole of `auto`: `native` when `frankenphp_handle_request()`
     * exists or `RR_MODE=http` is set, `pdo` for every other runtime.
     * Those two signals are the entire rule — no other environment
     * selects `native` by default, however long its PHP process lives.
     * AWS Lambda gets `pdo` here; a deployment wanting otherwise selects
     * `native` explicitly.
     */
    private static function shouldUseNativeDriverByDefault(): bool
    {
        return \function_exists('frankenphp_handle_request') || \getenv('RR_MODE') === 'http';
    }
}
