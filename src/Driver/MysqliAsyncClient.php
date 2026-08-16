<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Closure;
use Kinetis\Persistence\ConnectionOptions;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\MysqlTransaction;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Exception\ConnectionException;
use Kinetis\Persistence\Exception\QueryException;
use mysqli;
use mysqli_sql_exception;
use Revolt\EventLoop;
use SplQueue;
use Throwable;

/**
 * A MySQL client built on mysqli's native async mode (MYSQLI_ASYNC +
 * mysqli_poll + reap_async_query): the wire protocol and all waiting run
 * inside mysqlnd at C speed, while queries still overlap across
 * connections and suspend only their own Fiber — `concurrently()`
 * fan-out works at a fraction of a userland protocol client's CPU cost.
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
 * concurrently is delayed by at most one blocking window per loop turn.
 * The callback is disabled whenever no query is in flight, so an idle
 * client keeps the loop free to exit.
 *
 * One query is in flight per connection at a time (a protocol
 * constraint); {@see ConnectionOptions::$maxConnections} bounds the
 * fan-out width, and callers beyond it wait for a connection like any
 * pool.
 *
 * A dispatch-phase failure whose error indicates the pooled connection
 * itself died is retried once on a fresh connection — safe, because the
 * statement never reached the server (see {@see StaleConnectionException}).
 * Reap-phase failures are never retried.
 *
 * execute() realizes parameter binding as escaped client-side
 * interpolation (mysqli has no async prepared-statement execution);
 * every value is escaped via real_escape_string or numeric by
 * construction, against the connection's actual charset — which this
 * client always sets explicitly (utf8mb4 unless configured otherwise).
 */
final class MysqliAsyncClient implements MysqlLink
{
    private const int POLL_BLOCK_MICROSECONDS = 1000;

    private const string CLOSED_MESSAGE = 'The client has been closed';

    /** Client-side errno values meaning "the connection is gone" (CR_CONNECTION_ERROR, CR_SERVER_GONE_ERROR, CR_SERVER_LOST). */
    private const array GONE_ERRNOS = [2002, 2006, 2013];

    private readonly ConnectionOptions $options;

    /** @var array<int, mysqli> Every open connection, keyed by spl_object_id. */
    private array $connections = [];

    /** @var list<mysqli> */
    private array $idle = [];

    /** @var array<int, array{EventLoop\Suspension<SqlResult>, mysqli}> In-flight queries, keyed by spl_object_id of the connection. */
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

    public function __construct(
        private readonly string $host,
        private readonly string $user,
        #[\SensitiveParameter] private readonly string $password,
        private readonly string $database,
        private readonly int $port = 3306,
        ?ConnectionOptions $options = null,
    ) {
        $this->options = $options ?? new ConnectionOptions();
        // applicationName is a Postgres concept; free-form
        // connection-string text has no mysqli equivalent.
        $this->options->rejectUnsupported('native mysqli', ['applicationName', 'extraConnectionString']);
        $this->options->validateMysqlSsl('native mysqli');
        $this->waiters = new SplQueue();
    }

    /**
     * Opens pooled connections now instead of on first use — up to
     * $connections of them (the whole pool when null), never beyond
     * {@see ConnectionOptions::$maxConnections}.
     *
     * Load-bearing under a persistent worker, not just a latency
     * optimization: mysqli_poll() is select()-based in C, so it rejects
     * any connection whose file descriptor is numbered >= FD_SETSIZE
     * (1024) — a ceiling no event-loop extension can lift, since it
     * lives inside ext-mysqli itself. Connections opened at worker boot,
     * before request traffic exists, claim low descriptor numbers and
     * keep them for the process's lifetime; connections opened lazily
     * under load are numbered after every open client socket and can
     * land past the ceiling. Keep the total mysqli connections per
     * process (worker threads x maxConnections) under ~1000 for the
     * same reason.
     *
     * Throws {@see ConnectionException} if the server is unreachable —
     * a warmed pool is an explicit request, so failing to open it is an
     * error, not a silent fall-back to lazy connecting.
     */
    public function warmUp(?int $connections = null): void
    {
        if ($this->closed) {
            throw new ConnectionException(self::CLOSED_MESSAGE);
        }

        $target = \min($connections ?? $this->options->maxConnections, $this->options->maxConnections);

        while (\count($this->connections) < $target) {
            $this->idle[] = $this->connect();
        }
    }

    #[\Override]
    public function query(string $sql): SqlResult
    {
        return $this->runPooled(fn (mysqli $connection): SqlResult => $this->queryOn($connection, $sql));
    }

    #[\Override]
    public function execute(string $sql, array $params = []): SqlResult
    {
        return $this->runPooled(
            fn (mysqli $connection): SqlResult => $this->queryOn($connection, $this->interpolate($connection, $sql, $params)),
        );
    }

    #[\Override]
    public function beginTransaction(): MysqlTransaction
    {
        for ($attempt = 0; ; $attempt++) {
            $connection = $this->acquire();

            try {
                $this->queryOn($connection, 'START TRANSACTION');
            } catch (StaleConnectionException $e) {
                $this->release($connection);

                if ($attempt >= 1) {
                    throw new ConnectionException('MySQL connection lost during dispatch (after retry)', 0, $e);
                }

                continue;
            } catch (Throwable $e) {
                $this->release($connection);

                throw $e;
            }

            return new MysqliAsyncTransaction($this, $connection, function (mysqli $connection): void {
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

        if ($this->pollTimerId !== null) {
            EventLoop::cancel($this->pollTimerId);
            $this->pollTimerId = null;
        }

        foreach ($this->pending as [$suspension]) {
            $suspension->throw(new ConnectionException('The client was closed with a query in flight'));
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
    }

    #[\Override]
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Acquire → run → release, with the one-retry policy for
     * dispatch-phase deaths of pooled connections.
     *
     * @param Closure(mysqli): SqlResult $operation
     */
    private function runPooled(Closure $operation): SqlResult
    {
        for ($attempt = 0; ; $attempt++) {
            $connection = $this->acquire();

            try {
                return $operation($connection);
            } catch (StaleConnectionException $e) {
                if ($attempt >= 1) {
                    throw new ConnectionException('MySQL connection lost during dispatch (after retry)', 0, $e);
                }
                // Marked broken already; release() discards it and the
                // loop retries on a fresh connection.
            } finally {
                $this->release($connection);
            }
        }
    }

    /**
     * Dispatches $sql on an already-acquired connection and suspends the
     * calling Fiber until mysqli_poll() reports the result.
     *
     * @internal Also used by {@see MysqliAsyncTransaction}, which pins one
     *     connection for the transaction's lifetime.
     */
    public function queryOn(mysqli $connection, string $sql): SqlResult
    {
        if ($this->closed) {
            throw new ConnectionException(self::CLOSED_MESSAGE);
        }

        try {
            $dispatched = $connection->query($sql, \MYSQLI_ASYNC);
        } catch (mysqli_sql_exception $e) {
            throw $this->dispatchFailure($connection, $sql, $e);
        }

        if ($dispatched === false) {
            throw $this->dispatchFailure($connection, $sql, null);
        }

        $suspension = EventLoop::getSuspension();
        $this->pending[\spl_object_id($connection)] = [$suspension, $connection];
        $this->enablePolling();

        /** @var SqlResult */
        return $suspension->suspend();
    }

    /**
     * @param array<int|string, mixed> $params
     *
     * @internal Also used by {@see MysqliAsyncTransaction}.
     */
    public function executeOn(mysqli $connection, string $sql, array $params): SqlResult
    {
        return $this->queryOn($connection, $this->interpolate($connection, $sql, $params));
    }

    private function dispatchFailure(mysqli $connection, string $sql, ?mysqli_sql_exception $e): Throwable
    {
        $errno = $connection->errno;
        $message = $connection->error !== '' ? $connection->error : ($e?->getMessage() ?? 'Failed to dispatch query');

        if (\in_array($errno, self::GONE_ERRNOS, true)) {
            $this->broken[\spl_object_id($connection)] = true;

            return new StaleConnectionException("MySQL connection lost during dispatch: {$message}", 0, $e);
        }

        return new QueryException($message, $sql, $e);
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
                // A plain cast, never sprintf('%G'): printf-family float
                // formatting honors setlocale(LC_NUMERIC, ...), and a
                // locale with a comma decimal separator would emit "1,5"
                // — two SQL expressions, silently wrong results. PHP's
                // float-to-string cast is locale-independent and
                // round-trip exact.
                \is_float($value) && \is_finite($value) => (string) $value,
                \is_float($value) => throw new QueryException('Cannot bind a non-finite float (INF/NAN) as a SQL parameter'),
                \is_string($value) => "'" . $connection->real_escape_string($value) . "'",
                default => throw new QueryException(
                    'Unsupported parameter type ' . \get_debug_type($value) . ' — only scalars and null can be bound',
                ),
            };
        });
    }

    private function acquire(): mysqli
    {
        while (true) {
            if ($this->closed) {
                throw new ConnectionException(self::CLOSED_MESSAGE);
            }

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

        if (($broken || $this->closed) && isset($this->connections[$id])) {
            // Only tear down a connection this pool still tracks:
            // close() already closed everything it held — including a
            // connection pinned by an in-flight transaction, whose
            // finish() releases it afterwards — and mysqli throws on a
            // second close.
            unset($this->connections[$id]);

            try {
                $connection->close();
            } catch (mysqli_sql_exception) {
                // Already gone server-side; closing is best-effort.
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
        $options = $this->options;

        try {
            $connection = \mysqli_init();

            if ($connection === false) {
                throw new ConnectionException('mysqli_init() failed');
            }

            $connection->options(\MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);

            if ($options->connectTimeout !== null) {
                $connection->options(\MYSQLI_OPT_CONNECT_TIMEOUT, $options->connectTimeout);
            }

            $flags = $options->compression === true ? \MYSQLI_CLIENT_COMPRESS : 0;

            if ($options->wantsTls()) {
                // "require" encrypts without verifying the peer;
                // "verify-ca"/"verify-full" verify against the CA bundle.
                // mysqlnd's verification also checks the hostname, so
                // verify-ca behaves as verify-full here — stricter than
                // asked, never looser.
                $connection->ssl_set(null, null, $options->sslCa, null, null);
                $flags |= \MYSQLI_CLIENT_SSL;

                if ($options->sslMode === 'require') {
                    $flags |= \MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT;
                } else {
                    $connection->options(\MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, 1);
                }
            }

            // The "@" mirrors pg_connect()'s treatment in the pgsql
            // driver: a refused handshake (a TLS certificate failure,
            // for one) otherwise emits a PHP warning alongside the
            // mysqli_sql_exception this catch converts — the
            // ConnectionException is the one voice for connect failures.
            @$connection->real_connect(
                $this->host,
                $this->user,
                $this->password,
                $this->database,
                $this->port,
                flags: $flags,
            );
        } catch (mysqli_sql_exception $e) {
            throw new ConnectionException('Failed to connect to MySQL: ' . $e->getMessage(), 0, $e);
        }

        if ($connection->connect_errno !== 0) {
            throw new ConnectionException('Failed to connect to MySQL: ' . $connection->connect_error);
        }

        try {
            // Always set explicitly: real_escape_string in interpolate()
            // is charset-dependent, so the connection charset must never
            // be whatever the server happens to default to.
            $connection->set_charset($options->charset ?? 'utf8mb4');

            if ($options->collation !== null) {
                // Both values are constrained to identifier characters by
                // ConnectionOptions' constructor.
                $connection->query(\sprintf(
                    "SET NAMES '%s' COLLATE '%s'",
                    $options->charset ?? 'utf8mb4',
                    $options->collation,
                ));
            }
        } catch (mysqli_sql_exception $e) {
            throw new ConnectionException('Failed to configure MySQL connection: ' . $e->getMessage(), 0, $e);
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
            $this->disablePollTimerIfIdle();

            return;
        }

        $links = $this->pendingConnections();
        $read = $error = $reject = $links;

        try {
            $ready = \mysqli_poll($read, $error, $reject, 0, self::POLL_BLOCK_MICROSECONDS);
        } catch (mysqli_sql_exception $e) {
            $this->failAll($links, new ConnectionException('mysqli_poll() failed: ' . $e->getMessage(), 0, $e));

            return;
        }

        if ($ready === false) {
            // On failure mysqli_poll() leaves the by-ref arrays in an
            // unspecified state — acting on them could fail every
            // pending query against still-healthy connections. Fail
            // only what poll itself reported nothing about, loudly.
            $this->failAll($links, new ConnectionException('mysqli_poll() failed'));

            return;
        }

        if ($ready > 0) {
            foreach ($read as $connection) {
                $this->finishPending($connection);
            }
        }

        foreach ($error as $connection) {
            $this->failPending($connection, new ConnectionException(
                'MySQL connection error: ' . ($connection->error !== '' ? $connection->error : 'unknown'),
            ));
        }

        foreach ($reject as $connection) {
            $this->failPending($connection, new ConnectionException('mysqli_poll() rejected a connection with a pending query'));
        }

        $this->disablePollTimerIfIdle();
    }

    /** @return list<mysqli> */
    private function pendingConnections(): array
    {
        $links = [];

        foreach ($this->pending as [, $connection]) {
            $links[] = $connection;
        }

        return $links;
    }

    /** @param list<mysqli> $links */
    private function failAll(array $links, Throwable $exception): void
    {
        foreach ($links as $connection) {
            $this->failPending($connection, $exception);
        }
    }

    private function disablePollTimerIfIdle(): void
    {
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

        try {
            /** @var \mysqli_result|bool $result reap_async_query() returns true for queries without result sets; some stubs type only mysqli_result|false. */
            $result = $connection->reap_async_query();
        } catch (mysqli_sql_exception $e) {
            $suspension->throw(new QueryException($e->getMessage(), '', $e));

            return;
        }

        if ($result === false) {
            $suspension->throw(new QueryException($connection->error !== '' ? $connection->error : 'Query failed'));

            return;
        }

        if ($result === true) {
            $suspension->resume(new BufferedSqlResult(
                [],
                $connection->affected_rows >= 0 ? (int) $connection->affected_rows : null,
                null,
                $connection->insert_id !== 0 ? (int) $connection->insert_id : null,
            ));

            return;
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $result->fetch_all(\MYSQLI_ASSOC);
        $buffered = new BufferedSqlResult($rows, (int) $result->num_rows, $result->field_count);
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
