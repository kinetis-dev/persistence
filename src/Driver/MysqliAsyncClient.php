<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Amp\Mysql\MysqlLink;
use Amp\Mysql\MysqlResult;
use Amp\Mysql\MysqlStatement;
use Amp\Mysql\MysqlTransaction;
use Amp\Sql\SqlConnectionException;
use Amp\Sql\SqlException;
use Amp\Sql\SqlQueryError;
use Closure;
use mysqli;
use mysqli_sql_exception;
use Revolt\EventLoop;
use SplQueue;
use Throwable;

/**
 * A MySQL client built on mysqli's native async mode (MYSQLI_ASYNC +
 * mysqli_poll + reap_async_query): the wire protocol and all waiting run
 * inside mysqlnd at C speed, while queries still overlap across
 * connections and suspend only their own Fiber — so `concurrently()`
 * fan-out works exactly as it does with amphp/mysql, at a fraction of
 * the per-query CPU cost.
 *
 * Intended for persistent runtimes (FrankenPHP worker mode), where one
 * instance lives for the whole worker thread and its connections are
 * reused across requests. It works under PHP-FPM too, but there
 * {@see PdoMysqlClient} is the better fit — see
 * SqlConnectionFactory::fromConfig()'s driver selection.
 *
 * Event-loop integration: mysqli does not expose its socket file
 * descriptor, so its connections cannot be watched by Revolt directly.
 * While queries are in flight, this client instead runs a zero-interval
 * repeating callback that calls mysqli_poll() with a short blocking
 * window (POLL_BLOCK_MICROSECONDS). For a request whose only outstanding
 * work is database queries — the common case, since a worker thread
 * serves one request at a time — this behaves like a plain blocking
 * wait with no added latency. Other event-loop work scheduled
 * concurrently (an outbound HTTP call, a timer) is delayed by at most
 * one blocking window per loop turn. The callback is disabled whenever
 * no query is in flight, so an idle client keeps the loop free to exit.
 *
 * One query is in flight per connection at a time (a mysqli/protocol
 * constraint, same as amphp's pool); `maxConnections` bounds the fan-out
 * width, and callers beyond it wait for a connection like any pool.
 *
 * prepare() is not offered: mysqli has no async prepared-statement
 * execution, and execute() instead interpolates parameters client-side
 * via real_escape_string() — every value is escaped or numeric by
 * construction. Statement-shaped APIs that want a genuine server-side
 * prepare should use the amphp driver.
 */
final class MysqliAsyncClient implements MysqlLink
{
    private const int POLL_BLOCK_MICROSECONDS = 1000;

    /** @var array<int, mysqli> Every open connection, keyed by spl_object_id. */
    private array $connections = [];

    /** @var list<mysqli> */
    private array $idle = [];

    /** @var array<int, array{EventLoop\Suspension<MysqlResult>, mysqli}> In-flight queries, keyed by spl_object_id of the connection. */
    private array $pending = [];

    /**
     * @var array<int, true> Connections whose in-flight query failed at
     *     the connection level, keyed by spl_object_id. The owning
     *     Fiber's release() call consults this so a dead connection is
     *     discarded instead of returning to the idle pool.
     */
    private array $broken = [];

    /** @var SplQueue<EventLoop\Suspension<mysqli|null>> Fibers waiting for a free connection. */
    private SplQueue $waiters;

    private ?string $pollTimerId = null;

    private bool $closed = false;

    /** @var list<Closure(): void> */
    private array $onClose = [];

    private int $lastUsedAt;

    public function __construct(
        private readonly string $host,
        private readonly string $user,
        #[\SensitiveParameter] private readonly string $password,
        private readonly string $database,
        private readonly int $port = 3306,
        private readonly int $maxConnections = 8,
    ) {
        $this->waiters = new SplQueue();
        $this->lastUsedAt = \time();
    }

    public function query(string $sql): MysqlResult
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
    public function execute(string $sql, array $params = []): MysqlResult
    {
        $connection = $this->acquire();

        try {
            return $this->queryOn($connection, $this->interpolate($connection, $sql, $params));
        } finally {
            $this->release($connection);
        }
    }

    public function prepare(string $sql): MysqlStatement
    {
        throw new SqlException(
            'MysqliAsyncClient does not support prepare() — mysqli has no async statement execution. '
            . 'Use execute() (client-side escaped interpolation) or the amphp driver.',
        );
    }

    public function beginTransaction(): MysqlTransaction
    {
        $connection = $this->acquire();

        try {
            $this->queryOn($connection, 'START TRANSACTION');
        } catch (Throwable $e) {
            $this->release($connection);

            throw $e;
        }

        return new MysqliAsyncTransaction($this, $connection, function (mysqli $connection): void {
            $this->release($connection);
        });
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if ($this->pollTimerId !== null) {
            EventLoop::cancel($this->pollTimerId);
            $this->pollTimerId = null;
        }

        foreach ($this->pending as [$suspension]) {
            $suspension->throw(new SqlConnectionException('The client was closed with a query in flight'));
        }
        $this->pending = [];

        while (!$this->waiters->isEmpty()) {
            $this->waiters->dequeue()->resume(null);
        }

        foreach ($this->connections as $connection) {
            try {
                $connection->close();
            } catch (mysqli_sql_exception) {
                // Already gone; closing is best-effort.
            }
        }
        $this->connections = [];
        $this->idle = [];

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

    /**
     * Dispatches $sql on an already-acquired connection and suspends the
     * calling Fiber until mysqli_poll() reports the result.
     *
     * @internal Also used by {@see MysqliAsyncTransaction}, which pins one
     *     connection for the transaction's lifetime.
     */
    public function queryOn(mysqli $connection, string $sql): MysqlResult
    {
        if ($this->closed) {
            throw new SqlConnectionException('The client has been closed');
        }

        try {
            $dispatched = $connection->query($sql, \MYSQLI_ASYNC);
        } catch (mysqli_sql_exception $e) {
            throw new SqlQueryError($e->getMessage(), $sql, $e);
        }

        if ($dispatched === false) {
            throw new SqlQueryError($connection->error !== '' ? $connection->error : 'Failed to dispatch query', $sql);
        }

        $suspension = EventLoop::getSuspension();
        $this->pending[\spl_object_id($connection)] = [$suspension, $connection];
        $this->enablePolling();

        /** @var MysqlResult */
        return $suspension->suspend();
    }

    /**
     * @param array<int|string, mixed> $params
     *
     * @internal Also used by {@see MysqliAsyncTransaction}.
     */
    public function executeOn(mysqli $connection, string $sql, array $params): MysqlResult
    {
        return $this->queryOn($connection, $this->interpolate($connection, $sql, $params));
    }

    /**
     * @param array<int|string, mixed> $params
     */
    private function interpolate(mysqli $connection, string $sql, array $params): string
    {
        return SqlParamInterpolator::interpolate($sql, \array_values($params), static function (mixed $value) use ($connection): string {
            return match (true) {
                $value === null => 'NULL',
                \is_bool($value) => $value ? '1' : '0',
                \is_int($value) => (string) $value,
                \is_float($value) => \sprintf('%.17G', $value),
                \is_string($value) => "'" . $connection->real_escape_string($value) . "'",
                default => throw new SqlQueryError(
                    'Unsupported parameter type ' . \get_debug_type($value) . ' — only scalars and null can be bound',
                ),
            };
        });
    }

    private function acquire(): mysqli
    {
        while (true) {
            if ($this->closed) {
                throw new SqlConnectionException('The client has been closed');
            }

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

            if ($connection instanceof mysqli) {
                return $connection;
            }

            // A connection died instead of being handed over (or the
            // client closed) — loop to retry or fail on the closed check.
        }
    }

    /** @internal Called back by {@see MysqliAsyncTransaction} when it finishes. */
    public function release(mysqli $connection, bool $broken = false): void
    {
        $id = \spl_object_id($connection);

        if (isset($this->broken[$id])) {
            unset($this->broken[$id]);
            $broken = true;
        }

        if ($broken || $this->closed) {
            unset($this->connections[$id]);

            try {
                $connection->close();
            } catch (mysqli_sql_exception) {
                // Best-effort.
            }
        }

        if (!$this->waiters->isEmpty()) {
            $this->waiters->dequeue()->resume(($broken || $this->closed) ? null : $connection);

            return;
        }

        if (!$broken && !$this->closed) {
            $this->idle[] = $connection;
        }
    }

    private function connect(): mysqli
    {
        try {
            $connection = \mysqli_init();

            if ($connection === false) {
                throw new SqlConnectionException('mysqli_init() failed');
            }

            $connection->options(\MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);
            $connection->real_connect($this->host, $this->user, $this->password, $this->database, $this->port);
        } catch (mysqli_sql_exception $e) {
            throw new SqlConnectionException('Failed to connect to MySQL: ' . $e->getMessage(), 0, $e);
        }

        if ($connection->connect_errno !== 0) {
            throw new SqlConnectionException('Failed to connect to MySQL: ' . $connection->connect_error);
        }

        $this->connections[\spl_object_id($connection)] = $connection;

        return $connection;
    }

    private function enablePolling(): void
    {
        if ($this->pollTimerId === null) {
            $this->pollTimerId = EventLoop::repeat(0, function (): void {
                $this->poll();
            });

            return;
        }

        EventLoop::enable($this->pollTimerId);
    }

    private function poll(): void
    {
        if ($this->pending === []) {
            if ($this->pollTimerId !== null) {
                EventLoop::disable($this->pollTimerId);
            }

            return;
        }

        $links = [];

        foreach ($this->pending as [, $connection]) {
            $links[] = $connection;
        }

        $read = $error = $reject = $links;

        try {
            $ready = \mysqli_poll($read, $error, $reject, 0, self::POLL_BLOCK_MICROSECONDS);
        } catch (mysqli_sql_exception $e) {
            foreach ($links as $connection) {
                $this->failPending($connection, new SqlConnectionException('mysqli_poll() failed: ' . $e->getMessage(), 0, $e));
            }

            return;
        }

        if ($ready !== false && $ready > 0) {
            foreach ($read as $connection) {
                $this->finishPending($connection);
            }
        }

        foreach ($error as $connection) {
            $this->failPending($connection, new SqlConnectionException(
                'MySQL connection error: ' . ($connection->error !== '' ? $connection->error : 'unknown'),
            ));
        }

        foreach ($reject as $connection) {
            $this->failPending($connection, new SqlConnectionException('mysqli_poll() rejected a connection with a pending query'));
        }

        if ($this->pending === [] && $this->pollTimerId !== null) {
            EventLoop::disable($this->pollTimerId);
        }
    }

    private function finishPending(mysqli $connection): void
    {
        $id = \spl_object_id($connection);
        $entry = $this->pending[$id] ?? null;

        if ($entry === null) {
            return;
        }

        unset($this->pending[$id]);
        [$suspension] = $entry;
        $this->lastUsedAt = \time();

        try {
            /** @var \mysqli_result|bool $result reap_async_query() returns true for queries without result sets; some stubs type only mysqli_result|false. */
            $result = $connection->reap_async_query();
        } catch (mysqli_sql_exception $e) {
            $suspension->throw(new SqlQueryError($e->getMessage(), '', $e));

            return;
        }

        if ($result === false) {
            $suspension->throw(new SqlQueryError($connection->error !== '' ? $connection->error : 'Query failed', ''));

            return;
        }

        if ($result === true) {
            $suspension->resume(new BufferedMysqlResult(
                [],
                $connection->affected_rows >= 0 ? (int) $connection->affected_rows : null,
                null,
                $connection->insert_id !== 0 ? (int) $connection->insert_id : null,
            ));

            return;
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $result->fetch_all(\MYSQLI_ASSOC);
        $buffered = new BufferedMysqlResult($rows, (int) $result->num_rows, $result->field_count, null);
        $result->free();

        $suspension->resume($buffered);
    }

    private function failPending(mysqli $connection, Throwable $exception): void
    {
        $id = \spl_object_id($connection);
        $entry = $this->pending[$id] ?? null;

        if ($entry === null) {
            return;
        }

        unset($this->pending[$id]);
        $this->broken[$id] = true;
        $entry[0]->throw($exception);
    }
}
