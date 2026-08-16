<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Closure;
use Kinetis\Persistence\ConnectionOptions;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Contract\PostgresTransaction;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Exception\ConnectionException;
use Kinetis\Persistence\Exception\QueryException;
use PgSql\Result;
use Revolt\EventLoop;
use SplQueue;
use Throwable;

/**
 * A Postgres client built on ext-pgsql's native async API
 * (pg_send_query/pg_send_query_params + pg_get_result), with genuine
 * event-loop integration: libpq exports its socket (pg_socket()), so
 * every connection is watched by a Revolt onReadable callback and a
 * waiting Fiber consumes zero CPU until its result actually arrives —
 * no polling anywhere.
 *
 * The wire protocol runs inside libpq at C speed; execute() uses real
 * server-side parameters via pg_send_query_params ("?" placeholders are
 * rewritten to "$1".."$n"). Numeric/bool columns are converted to native
 * PHP types by field-type inspection.
 *
 * One query is in flight per connection;
 * {@see ConnectionOptions::$maxConnections} bounds fan-out width, and
 * callers beyond it wait for a connection like any pool. Dispatch-phase
 * failures on a dead pooled connection are retried once on a fresh one;
 * reap-phase failures never are, so a connection that dies while pooled
 * costs the caller one QueryException before the retry path takes over
 * — see {@see StaleConnectionException} for the full sequence.
 *
 * Intended primarily for persistent runtimes (FrankenPHP worker mode)
 * where connections outlive requests; under PHP-FPM prefer
 * {@see PdoPgsqlClient} via SqlConnectionFactory's driver selection.
 *
 * Opening a connection (pool fill only) uses the blocking pg_connect();
 * queries themselves never block. PGSQL_CONNECT_ASYNC exists should
 * connect latency under load ever warrant a handshake state machine.
 */
final class PgsqlAsyncClient implements PostgresLink
{
    private readonly ConnectionOptions $options;

    /** @var array<int, PgsqlAsyncConnection> keyed by spl_object_id */
    private array $connections = [];

    /** @var list<PgsqlAsyncConnection> */
    private array $idle = [];

    /** @var SplQueue<EventLoop\Suspension<PgsqlAsyncConnection|null>> */
    private SplQueue $waiters;

    private bool $closed = false;

    public function __construct(
        private readonly string $host,
        private readonly string $user,
        #[\SensitiveParameter] private readonly string $password,
        private readonly string $database,
        private readonly int $port = 5432,
        ?ConnectionOptions $options = null,
    ) {
        $this->options = $options ?? new ConnectionOptions();
        // Collation and protocol compression are MySQL concepts.
        $this->options->rejectUnsupported('native pgsql', ['collation', 'compression']);
        $this->waiters = new SplQueue();
    }

    /**
     * Opens pooled connections now instead of on first use — up to
     * $connections of them (the whole pool when null), never beyond
     * {@see ConnectionOptions::$maxConnections}. Saves the first
     * requests' connection handshakes; this driver has no descriptor-
     * numbering constraint (its sockets are watched through the event
     * loop, where an extension backend lifts the select() limit —
     * unlike {@see MysqliAsyncClient::warmUp()}, where warming is
     * load-bearing).
     *
     * Throws {@see ConnectionException} if the server is unreachable —
     * a warmed pool is an explicit request, so failing to open it is an
     * error, not a silent fall-back to lazy connecting.
     */
    public function warmUp(?int $connections = null): void
    {
        $this->assertOpen();

        $target = \min($connections ?? $this->options->maxConnections, $this->options->maxConnections);

        while (\count($this->connections) < $target) {
            $this->idle[] = $this->connect();
        }
    }

    #[\Override]
    public function query(string $sql): SqlResult
    {
        return $this->runPooled(fn (PgsqlAsyncConnection $connection): SqlResult => $this->queryOn($connection, $sql));
    }

    #[\Override]
    public function execute(string $sql, array $params = []): SqlResult
    {
        return $this->runPooled(
            fn (PgsqlAsyncConnection $connection): SqlResult => $this->executeOn($connection, $sql, $params),
        );
    }

    #[\Override]
    public function beginTransaction(): PostgresTransaction
    {
        for ($attempt = 0; ; $attempt++) {
            $connection = $this->acquire();

            try {
                $this->queryOn($connection, 'BEGIN');
            } catch (StaleConnectionException $e) {
                $this->release($connection);

                if ($attempt >= 1) {
                    throw new ConnectionException('Postgres connection lost during dispatch (after retry)', 0, $e);
                }

                continue;
            } catch (Throwable $e) {
                $this->release($connection);

                throw $e;
            }

            return new PgsqlAsyncTransaction($this, $connection, function (PgsqlAsyncConnection $connection): void {
                $this->release($connection);
            });
        }
    }

    #[\Override]
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        foreach ($this->connections as $connection) {
            EventLoop::cancel($connection->watcherId);
            $connection->suspension?->throw(new ConnectionException('The client was closed with a query in flight'));
            $connection->suspension = null;
            \pg_close($connection->handle);
        }
        $this->connections = [];
        $this->idle = [];

        while (!$this->waiters->isEmpty()) {
            $this->waiters->dequeue()->resume(null);
        }
    }

    #[\Override]
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * @param Closure(PgsqlAsyncConnection): SqlResult $operation
     */
    private function runPooled(Closure $operation): SqlResult
    {
        for ($attempt = 0; ; $attempt++) {
            $connection = $this->acquire();

            try {
                return $operation($connection);
            } catch (StaleConnectionException $e) {
                if ($attempt >= 1) {
                    throw new ConnectionException('Postgres connection lost during dispatch (after retry)', 0, $e);
                }
            } finally {
                $this->release($connection);
            }
        }
    }

    /** @internal Also used by {@see PgsqlAsyncTransaction}. */
    public function queryOn(PgsqlAsyncConnection $connection, string $sql): SqlResult
    {
        $this->assertOpen();

        if (!@\pg_send_query($connection->handle, $sql)) {
            throw $this->dispatchFailure($connection, $sql);
        }

        return $this->awaitResult($connection);
    }

    /**
     * @param array<int|string, mixed> $params
     *
     * @internal Also used by {@see PgsqlAsyncTransaction}.
     */
    public function executeOn(PgsqlAsyncConnection $connection, string $sql, array $params): SqlResult
    {
        $this->assertOpen();

        $rewritten = SqlParamInterpolator::interpolate(
            $sql,
            \array_values($params),
            static fn (mixed $value, int $index): string => '$' . ($index + 1),
        );

        $encoded = \array_map(static fn (mixed $value): ?string => match (true) {
            $value === null => null,
            \is_bool($value) => $value ? 't' : 'f',
            \is_int($value), \is_float($value), \is_string($value) => (string) $value,
            default => throw new QueryException(
                'Unsupported parameter type ' . \get_debug_type($value) . ' — only scalars and null can be bound',
            ),
        }, \array_values($params));

        if (!@\pg_send_query_params($connection->handle, $rewritten, $encoded)) {
            throw $this->dispatchFailure($connection, $sql);
        }

        return $this->awaitResult($connection);
    }

    private function dispatchFailure(PgsqlAsyncConnection $connection, string $sql): Throwable
    {
        $connection->broken = true;
        $message = \pg_last_error($connection->handle) ?: 'Failed to dispatch query';

        if (\pg_connection_status($connection->handle) !== \PGSQL_CONNECTION_OK) {
            return new StaleConnectionException("Postgres connection lost during dispatch: {$message}");
        }

        return new QueryException($message, $sql);
    }

    private function awaitResult(PgsqlAsyncConnection $connection): SqlResult
    {
        $connection->suspension = EventLoop::getSuspension();
        EventLoop::enable($connection->watcherId);

        /** @var SqlResult */
        return $connection->suspension->suspend();
    }

    /**
     * The onReadable callback for one connection: consume input; once
     * libpq has the complete result, drain it and resume the waiting
     * Fiber.
     */
    private function onReadable(PgsqlAsyncConnection $connection): void
    {
        if (!\pg_consume_input($connection->handle)) {
            $connection->broken = true;
            $this->settle($connection, null, new ConnectionException(
                'Postgres connection error: ' . \pg_last_error($connection->handle),
            ));

            return;
        }

        // Suppressed: on a connection the server has already torn down,
        // libpq emits a "cannot set connection to blocking mode" notice
        // here before the loop below settles the waiter with a real
        // exception. Same @-plus-clean-exception handling pg_connect()
        // and mysqli's real_connect() already get.
        if (@\pg_connection_busy($connection->handle)) {
            return; // Result not complete yet; wait for more input.
        }

        $last = null;
        $error = null;

        while (($result = \pg_get_result($connection->handle)) !== false) {
            $status = \pg_result_status($result);

            if ($status === \PGSQL_FATAL_ERROR || $status === \PGSQL_BAD_RESPONSE) {
                $error ??= new QueryException(\pg_result_error($result) ?: 'Query failed');
                continue;
            }

            $last = $result;
        }

        if ($error !== null || $last === null) {
            $this->settle($connection, null, $error ?? new QueryException('Query produced no result'));

            return;
        }

        $this->settle($connection, $this->buildResult($last), null);
    }

    private function settle(PgsqlAsyncConnection $connection, ?SqlResult $result, ?Throwable $error): void
    {
        EventLoop::disable($connection->watcherId);

        $suspension = $connection->suspension;
        $connection->suspension = null;

        if ($suspension === null) {
            return;
        }

        if ($error !== null) {
            $suspension->throw($error);

            return;
        }

        \assert($result !== null);
        $suspension->resume($result);
    }

    private function buildResult(Result $result): BufferedSqlResult
    {
        $fieldCount = \pg_num_fields($result);

        if ($fieldCount <= 0) {
            return new BufferedSqlResult([], \pg_affected_rows($result), null);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = \pg_fetch_all($result, \PGSQL_ASSOC);
        $rows = self::applyConverters($rows, $this->buildColumnConverters($result, $fieldCount));

        return new BufferedSqlResult($rows, \pg_num_rows($result), $fieldCount);
    }

    /**
     * pg returns every value as a string; this maps each column to a
     * converter back to its native PHP type, by field type.
     *
     * @return array<string, Closure(?string): (int|float|bool|null)>
     */
    private function buildColumnConverters(Result $result, int $fieldCount): array
    {
        $converters = [];

        for ($i = 0; $i < $fieldCount; $i++) {
            $type = \pg_field_type($result, $i);
            $name = \pg_field_name($result, $i);

            $converters[$name] = match ($type) {
                'int2', 'int4', 'int8', 'oid' => static fn (?string $v): ?int => $v === null ? null : (int) $v,
                // numeric (DECIMAL) deliberately stays a string: it is
                // arbitrary-precision, a float cast silently loses
                // digits, and every other driver (PDO both dialects,
                // mysqli) returns DECIMAL columns as strings.
                'float4', 'float8' => static fn (?string $v): ?float => $v === null ? null : (float) $v,
                'bool' => static fn (?string $v): ?bool => $v === null ? null : $v === 't',
                default => null,
            };
        }

        return \array_filter($converters);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, Closure(?string): (int|float|bool|null)> $converters
     * @return list<array<string, mixed>>
     */
    private static function applyConverters(array $rows, array $converters): array
    {
        foreach ($rows as &$row) {
            foreach ($converters as $column => $convert) {
                $row[$column] = $convert($row[$column]);
            }
        }
        unset($row);

        return $rows;
    }

    private function acquire(): PgsqlAsyncConnection
    {
        while (true) {
            $this->assertOpen();

            $connection = \array_pop($this->idle);

            if ($connection !== null) {
                return $connection;
            }

            if (\count($this->connections) < $this->options->maxConnections) {
                return $this->connect();
            }

            $suspension = EventLoop::getSuspension();
            $this->waiters->enqueue($suspension);
            $connection = $suspension->suspend();

            if ($connection instanceof PgsqlAsyncConnection) {
                return $connection;
            }
        }
    }

    /** @internal Called back by {@see PgsqlAsyncTransaction} when it finishes. */
    public function release(PgsqlAsyncConnection $connection): void
    {
        $id = \spl_object_id($connection);

        if ($connection->broken || $this->closed) {
            // Only tear down a connection this pool still tracks:
            // close() already cancelled and closed everything it held —
            // including a connection pinned by an in-flight transaction,
            // whose finish() releases it afterwards — and closing a
            // libpq handle twice is an error, not a no-op.
            if (isset($this->connections[$id])) {
                unset($this->connections[$id]);
                EventLoop::cancel($connection->watcherId);
                \pg_close($connection->handle);
            }

            if (!$this->waiters->isEmpty()) {
                $this->waiters->dequeue()->resume(null);
            }

            return;
        }

        if (!$this->waiters->isEmpty()) {
            $this->waiters->dequeue()->resume($connection);

            return;
        }

        $this->idle[] = $connection;
    }

    private function connect(): PgsqlAsyncConnection
    {
        $quote = static fn (string $value): string => "'" . \addcslashes($value, "'\\") . "'";

        $connectionString = \sprintf(
            'host=%s port=%d dbname=%s user=%s password=%s',
            $quote($this->host),
            $this->port,
            $quote($this->database),
            $quote($this->user),
            $quote($this->password),
        );

        // The canonical option set, translated to libpq's connection
        // string keys.
        if ($this->options->charset !== null) {
            $connectionString .= ' client_encoding=' . $quote($this->options->charset);
        }

        if ($this->options->sslMode !== null) {
            $connectionString .= ' sslmode=' . $quote($this->options->sslMode);
        }

        if ($this->options->sslCa !== null) {
            $connectionString .= ' sslrootcert=' . $quote($this->options->sslCa);
        }

        if ($this->options->connectTimeout !== null) {
            $connectionString .= " connect_timeout={$this->options->connectTimeout}";
        }

        if ($this->options->applicationName !== null) {
            $connectionString .= ' application_name=' . $quote($this->options->applicationName);
        }

        if ($this->options->extraConnectionString !== '') {
            // libpq's own parser validates these; unknown keys fail the
            // connect loudly rather than being silently dropped.
            $connectionString .= ' ' . $this->options->extraConnectionString;
        }

        // FORCE_NEW: pg_connect() otherwise silently reuses one shared
        // handle for identical connection strings, collapsing the pool
        // to a single real connection.
        $handle = @\pg_connect($connectionString, \PGSQL_CONNECT_FORCE_NEW);

        if ($handle === false) {
            throw new ConnectionException('Failed to connect to Postgres');
        }

        $socket = \pg_socket($handle);

        if ($socket === false) {
            \pg_close($handle);

            throw new ConnectionException('Failed to export the Postgres connection socket');
        }

        $connection = new PgsqlAsyncConnection($handle, $socket);
        $connection->watcherId = EventLoop::disable(EventLoop::onReadable($socket, function () use ($connection): void {
            $this->onReadable($connection);
        }));

        $this->connections[\spl_object_id($connection)] = $connection;

        return $connection;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new ConnectionException('The client has been closed');
        }
    }
}
