<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Amp\Postgres\PostgresLink;
use Amp\Postgres\PostgresResult;
use Amp\Postgres\PostgresStatement;
use Amp\Postgres\PostgresTransaction;
use Amp\Sql\SqlConnectionException;
use Amp\Sql\SqlException;
use Amp\Sql\SqlQueryError;
use Closure;
use PgSql\Result;
use Revolt\EventLoop;
use SplQueue;
use Throwable;

/**
 * A Postgres client built on ext-pgsql's native async API
 * (pg_send_query/pg_send_query_params + pg_get_result), with genuine
 * event-loop integration: unlike mysqli, libpq exports its socket
 * (pg_socket()), so every connection is watched by a Revolt onReadable
 * callback and a waiting Fiber consumes zero CPU until its result
 * actually arrives — no polling anywhere.
 *
 * The wire protocol runs inside libpq at C speed; execute() uses real
 * server-side parameters via pg_send_query_params ("?" placeholders are
 * rewritten to "$1".."$n"). Numeric/bool columns are converted to native
 * PHP types by field-type inspection, matching amphp/postgres's
 * behavior for the common types.
 *
 * One query is in flight per connection; `maxConnections` bounds fan-out
 * width, and callers beyond it wait for a connection like any pool.
 * Intended primarily for persistent runtimes (FrankenPHP worker mode)
 * where connections outlive requests; under PHP-FPM prefer
 * {@see PdoPgsqlClient} via SqlConnectionFactory's driver selection.
 */
final class PgsqlAsyncClient implements PostgresLink
{
    /** @var array<int, PgsqlAsyncConnection> keyed by spl_object_id */
    private array $connections = [];

    /** @var list<PgsqlAsyncConnection> */
    private array $idle = [];

    /** @var SplQueue<EventLoop\Suspension<PgsqlAsyncConnection|null>> */
    private SplQueue $waiters;

    private bool $closed = false;

    /** @var list<Closure(): void> */
    private array $onClose = [];

    private int $lastUsedAt;

    public function __construct(
        private readonly string $host,
        private readonly string $user,
        #[\SensitiveParameter] private readonly string $password,
        private readonly string $database,
        private readonly int $port = 5432,
        private readonly int $maxConnections = 8,
    ) {
        $this->waiters = new SplQueue();
        $this->lastUsedAt = \time();
    }

    public function query(string $sql): PostgresResult
    {
        $connection = $this->acquire();

        try {
            return $this->queryOn($connection, $sql);
        } finally {
            $this->release($connection);
        }
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function execute(string $sql, array $params = []): PostgresResult
    {
        $connection = $this->acquire();

        try {
            return $this->executeOn($connection, $sql, $params);
        } finally {
            $this->release($connection);
        }
    }

    public function prepare(string $sql): PostgresStatement
    {
        throw new SqlException(
            'PgsqlAsyncClient does not support prepare() yet — use execute(), which runs real '
            . 'server-side parameter binding per call via pg_send_query_params.',
        );
    }

    public function beginTransaction(): PostgresTransaction
    {
        $connection = $this->acquire();

        try {
            $this->queryOn($connection, 'BEGIN');
        } catch (Throwable $e) {
            $this->release($connection);

            throw $e;
        }

        return new PgsqlAsyncTransaction($this, $connection, function (PgsqlAsyncConnection $connection): void {
            $this->release($connection);
        });
    }

    public function notify(string $channel, string $payload = ''): PostgresResult
    {
        $sql = 'NOTIFY ' . $this->quoteIdentifier($channel)
            . ($payload === '' ? '' : ', ' . $this->quoteLiteral($payload));

        return $this->query($sql);
    }

    public function quoteLiteral(string $data): string
    {
        $quoted = \pg_escape_literal($this->anyHandle(), $data);

        if ($quoted === false) {
            throw new SqlException('Failed to quote literal');
        }

        return $quoted;
    }

    public function quoteIdentifier(string $name): string
    {
        $quoted = \pg_escape_identifier($this->anyHandle(), $name);

        if ($quoted === false) {
            throw new SqlException('Failed to quote identifier');
        }

        return $quoted;
    }

    public function escapeByteA(string $data): string
    {
        return \pg_escape_bytea($this->anyHandle(), $data);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        foreach ($this->connections as $connection) {
            EventLoop::cancel($connection->watcherId);
            $connection->suspension?->throw(new SqlConnectionException('The client was closed with a query in flight'));
            $connection->suspension = null;
            \pg_close($connection->handle);
        }
        $this->connections = [];
        $this->idle = [];

        while (!$this->waiters->isEmpty()) {
            $this->waiters->dequeue()->resume(null);
        }

        foreach ($this->onClose as $onClose) {
            $onClose();
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function onClose(Closure $onClose): void
    {
        if ($this->closed) {
            $onClose();

            return;
        }

        $this->onClose[] = $onClose;
    }

    public function getLastUsedAt(): int
    {
        return $this->lastUsedAt;
    }

    /** @internal Also used by {@see PgsqlAsyncTransaction}. */
    public function queryOn(PgsqlAsyncConnection $connection, string $sql): PostgresResult
    {
        $this->assertOpen();

        if (!\pg_send_query($connection->handle, $sql)) {
            $connection->broken = true;

            throw new SqlQueryError('Failed to dispatch query: ' . \pg_last_error($connection->handle), $sql);
        }

        return $this->awaitResult($connection);
    }

    /**
     * @param array<int|string, mixed> $params
     *
     * @internal Also used by {@see PgsqlAsyncTransaction}.
     */
    public function executeOn(PgsqlAsyncConnection $connection, string $sql, array $params): PostgresResult
    {
        $this->assertOpen();

        $position = 0;
        $rewritten = SqlParamInterpolator::interpolate($sql, \array_values($params), static function (mixed $value, int $index) use (&$position): string {
            $position = $index + 1;

            return '$' . $position;
        });

        $encoded = \array_map(static fn (mixed $value): ?string => match (true) {
            $value === null => null,
            \is_bool($value) => $value ? 't' : 'f',
            \is_int($value), \is_float($value), \is_string($value) => (string) $value,
            default => throw new SqlQueryError(
                'Unsupported parameter type ' . \get_debug_type($value) . ' — only scalars and null can be bound',
            ),
        }, \array_values($params));

        if (!\pg_send_query_params($connection->handle, $rewritten, $encoded)) {
            $connection->broken = true;

            throw new SqlQueryError('Failed to dispatch query: ' . \pg_last_error($connection->handle), $sql);
        }

        return $this->awaitResult($connection);
    }

    private function awaitResult(PgsqlAsyncConnection $connection): PostgresResult
    {
        $connection->suspension = EventLoop::getSuspension();
        EventLoop::enable($connection->watcherId);

        /** @var PostgresResult */
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
            $this->settle($connection, null, new SqlConnectionException(
                'Postgres connection error: ' . \pg_last_error($connection->handle),
            ));
            $connection->broken = true;

            return;
        }

        if (\pg_connection_busy($connection->handle)) {
            return; // Result not complete yet; wait for more input.
        }

        $last = null;
        $error = null;

        while (($result = \pg_get_result($connection->handle)) !== false) {
            $status = \pg_result_status($result);

            if ($status === \PGSQL_FATAL_ERROR || $status === \PGSQL_BAD_RESPONSE) {
                $error ??= new SqlQueryError(\pg_result_error($result) ?: 'Query failed', '');
                continue;
            }

            $last = $result;
        }

        $this->lastUsedAt = \time();

        if ($error !== null || $last === null) {
            $this->settle($connection, null, $error ?? new SqlQueryError('Query produced no result', ''));

            return;
        }

        $this->settle($connection, $this->buildResult($last), null);
    }

    private function settle(PgsqlAsyncConnection $connection, ?PostgresResult $result, ?Throwable $error): void
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

    private function buildResult(Result $result): BufferedPostgresResult
    {
        $fieldCount = \pg_num_fields($result);

        if ($fieldCount <= 0) {
            return new BufferedPostgresResult([], \pg_affected_rows($result), null);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = \pg_fetch_all($result, \PGSQL_ASSOC);

        // pg returns every value as a string; convert the common numeric
        // and boolean field types to native PHP types by column.
        $converters = [];

        for ($i = 0; $i < $fieldCount; $i++) {
            $type = \pg_field_type($result, $i);
            $name = \pg_field_name($result, $i);

            $converters[$name] = match ($type) {
                'int2', 'int4', 'int8', 'oid' => static fn (?string $v): ?int => $v === null ? null : (int) $v,
                'float4', 'float8', 'numeric' => static fn (?string $v): ?float => $v === null ? null : (float) $v,
                'bool' => static fn (?string $v): ?bool => $v === null ? null : $v === 't',
                default => null,
            };
        }

        $converters = \array_filter($converters);

        if ($converters !== []) {
            foreach ($rows as &$row) {
                foreach ($converters as $column => $convert) {
                    $row[$column] = $convert($row[$column]);
                }
            }
            unset($row);
        }

        return new BufferedPostgresResult($rows, \pg_num_rows($result), $fieldCount);
    }

    private function acquire(): PgsqlAsyncConnection
    {
        while (true) {
            $this->assertOpen();

            $connection = \array_pop($this->idle);

            if ($connection !== null) {
                return $connection;
            }

            if (\count($this->connections) < $this->maxConnections) {
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
            unset($this->connections[$id]);
            EventLoop::cancel($connection->watcherId);
            \pg_close($connection->handle);

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
        $connectionString = \sprintf(
            "host='%s' port=%d dbname='%s' user='%s' password='%s'",
            \addcslashes($this->host, "'\\"),
            $this->port,
            \addcslashes($this->database, "'\\"),
            \addcslashes($this->user, "'\\"),
            \addcslashes($this->password, "'\\"),
        );

        // FORCE_NEW: pg_connect() otherwise silently reuses one shared
        // handle for identical connection strings, collapsing the pool
        // to a single real connection.
        $handle = @\pg_connect($connectionString, \PGSQL_CONNECT_FORCE_NEW);

        if ($handle === false) {
            throw new SqlConnectionException('Failed to connect to Postgres');
        }

        $socket = \pg_socket($handle);

        if ($socket === false) {
            \pg_close($handle);

            throw new SqlConnectionException('Failed to export the Postgres connection socket');
        }

        $connection = new PgsqlAsyncConnection($handle, $socket);
        $connection->watcherId = EventLoop::disable(EventLoop::onReadable($socket, function () use ($connection): void {
            $this->onReadable($connection);
        }));

        $this->connections[\spl_object_id($connection)] = $connection;

        return $connection;
    }

    private function anyHandle(): \PgSql\Connection
    {
        $this->assertOpen();

        foreach ($this->connections as $connection) {
            return $connection->handle;
        }

        // No connection yet — open the first and keep it available.
        $connection = $this->connect();
        $this->idle[] = $connection;

        return $connection->handle;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new SqlConnectionException('The client has been closed');
        }
    }
}
