<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Closure;
use Kinetis\Instrumentation\Telemetry;
use Kinetis\Persistence\ConnectionOptions;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Contract\PostgresTransaction;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Exception\ConnectionException;
use Kinetis\Persistence\Exception\QueryException;
use PgSql\Connection;
use PgSql\Result;
use Revolt\EventLoop;
use Socket;
use SplQueue;
use Throwable;

/**
 * A Postgres client built on ext-pgsql's native async API
 * (pg_send_query/pg_send_query_params + pg_get_result). libpq exports its
 * socket (pg_socket()), so every connection is watched by Revolt and a
 * waiting Fiber consumes no CPU until its result arrives.
 *
 * Nothing on a connection's path blocks the event loop:
 *
 * - **Connecting** runs PGSQL_CONNECT_ASYNC and drives pg_connect_poll()
 *   from readiness waits, bounded by `DB_CONNECT_TIMEOUT`. Hostname
 *   resolution is the one exception: libpq resolves synchronously inside
 *   pg_connect(), so a slow resolver stalls the worker thread for as
 *   long as it takes.
 * - **Dispatch** puts libpq in nonblocking mode first
 *   ({@see setNonBlocking()}), so a parameter larger than its output
 *   buffer leaves bytes queued instead of flushing them synchronously.
 *   Both watchers push those out ({@see await()}), since the server
 *   talks back while a statement is still going out.
 * - **Disposal** shuts the transport down under libpq
 *   ({@see abort()}) rather than draining, cancelling or resetting, all
 *   of which wait on a server.
 *
 * The wire protocol runs inside libpq at C speed; execute() uses real
 * server-side parameters via pg_send_query_params ("?" placeholders are
 * rewritten to "$1".."$n"). Numeric/bool columns are converted to native
 * PHP types by field-type inspection.
 *
 * One statement is in flight per connection;
 * {@see ConnectionOptions::$maxConnections} bounds fan-out width, and
 * callers beyond it wait for a connection like any pool — a slot is
 * reserved for the whole of an opening attempt, since connecting
 * suspends. Dispatch-phase failures on a dead pooled connection are
 * retried once on a fresh one; reap-phase failures never are, so a
 * connection that dies while pooled costs the caller one QueryException
 * before the retry path takes over — see {@see StaleConnectionException}
 * for the full sequence.
 *
 * Intended primarily for persistent runtimes (FrankenPHP worker mode, or
 * RoadRunner) where connections outlive requests; under PHP-FPM prefer
 * {@see PdoPgsqlClient} via SqlConnectionFactory's driver selection.
 */
final class PgsqlAsyncClient implements PostgresLink
{
    private const string ABORTED_MESSAGE = 'The Postgres connection was closed with a statement in flight; whether the server ran it is unknown';

    private const string CLOSED_MESSAGE = 'The client has been closed';

    private const string COPY_MESSAGE = 'COPY is not supported by this driver: it puts the connection into a streaming mode the event loop cannot wait on, so the connection was taken out of service. Use a server-side COPY, or ordinary statements.';

    /** socket_shutdown()'s both-directions mode; ext-sockets names no constant for it. */
    private const int SHUT_RDWR = 2;

    private readonly ConnectionOptions $options;

    /** @var array<int, PgsqlAsyncConnection> keyed by spl_object_id */
    private array $connections = [];

    /** @var list<PgsqlAsyncConnection> */
    private array $idle = [];

    /**
     * Connection attempts in flight, keyed by attempt number: the libpq
     * handle being polled, and the waker of the Fiber suspended on the
     * next readiness event. Each entry holds a pool slot, so concurrent
     * Fibers cannot start more attempts than the pool has room for.
     *
     * {@see close()} reaches an attempt through this: it wakes the
     * Fiber, whose own cleanup cancels its watchers, and drops the entry
     * — which is what tells that Fiber to publish nothing.
     *
     * @var array<int, array{Connection, (Closure(?Throwable): void)|null}>
     */
    private array $opening = [];

    private int $attempts = 0;

    /** @var SplQueue<EventLoop\Suspension<PgsqlAsyncConnection|null>> */
    private SplQueue $waiters;

    private bool $closed = false;

    /**
     * The pre-flight every execute() passes before this client touches
     * its pool. A transaction pinning one of these connections keeps
     * its own ({@see AbstractTransaction}).
     */
    private readonly SqlParamPreflight $preflight;

    /** Which Fibers hold a transaction on this client — see {@see FiberTransactions}. */
    private readonly FiberTransactions $transactions;

    public function __construct(
        private readonly string $host,
        private readonly string $user,
        #[\SensitiveParameter] private readonly string $password,
        private readonly string $database,
        private readonly int $port = 5432,
        ?ConnectionOptions $options = null,
    ) {
        // Disposal ends a connection's transport with socket_shutdown()
        // (see abort()), and there is no non-blocking way to do it
        // without ext-sockets. Refusing here is what keeps the driver
        // from opening connections it could not take out of service.
        if (!\function_exists('socket_import_stream')) {
            throw new ConnectionException(
                'The native pgsql driver needs ext-sockets: it ends a connection\'s transport with '
                . 'socket_shutdown() so a statement in flight can be abandoned without blocking the '
                . 'event loop. Install ext-sockets, or set DB_DRIVER=pdo.',
            );
        }

        $this->options = $options ?? new ConnectionOptions();
        // Collation and protocol compression are MySQL concepts.
        $this->options->rejectUnsupported('native pgsql', ['collation', 'compression']);
        $this->preflight = new SqlParamPreflight(SqlDialect::Postgres);
        $this->transactions = new FiberTransactions();
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
            $this->idle[] = $this->open();
        }
    }

    #[\Override]
    public function query(string $sql): SqlResult
    {
        $this->transactions->assertNone();

        return $this->runPooled($sql, fn (PgsqlAsyncConnection $connection): SqlResult => $this->queryOn($connection, $sql));
    }

    #[\Override]
    public function execute(string $sql, array $params = []): SqlResult
    {
        $this->transactions->assertNone();

        // Ahead of runPooled(), which opens the span and takes a
        // connection — {@see SqlParamPreflight} for why that ordering is
        // the contract.
        $query = $this->preflight->run($sql, $params);

        return $this->runPooled(
            $sql,
            fn (PgsqlAsyncConnection $connection): SqlResult => $this->executeOn($connection, $query),
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

            $owner = $this->transactions->open();

            return new PgsqlAsyncTransaction($this, $connection, function (PgsqlAsyncConnection $connection, bool $discard) use ($owner): void {
                $this->transactions->close($owner);
                $this->release($connection, $discard);
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
            $this->abort($connection);
        }

        // A connection still being opened has no pool entry yet. Waking
        // its Fiber is what cancels the watchers on this half-open
        // handle, and dropping the entry first is what stops that Fiber
        // publishing the connection it was about to finish.
        foreach ($this->opening as $id => [$handle, $wake]) {
            unset($this->opening[$id]);
            $wake?->__invoke(new ConnectionException(self::CLOSED_MESSAGE));
            \pg_close($handle);
        }

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
    private function runPooled(string $sql, Closure $operation): SqlResult
    {
        $telemetry = Telemetry::global();
        $token = $telemetry->queryDispatched('postgresql', $sql);

        try {
            for ($attempt = 0; ; $attempt++) {
                $connection = $this->acquire();
                // The gap between queryDispatched and here is time spent
                // waiting for a free pooled connection. Fires again on a
                // stale-connection retry, marking the second attempt.
                $telemetry->queryServerStarted($token);

                try {
                    $result = $operation($connection);
                    $telemetry->queryReaped($token, null);

                    return $result;
                } catch (StaleConnectionException $e) {
                    if ($attempt >= 1) {
                        throw new ConnectionException('Postgres connection lost during dispatch (after retry)', 0, $e);
                    }
                } finally {
                    $this->release($connection);
                }
            }
        } catch (Throwable $e) {
            $telemetry->queryReaped($token, $e);

            throw $e;
        }
    }

    /** @internal Also used by {@see PgsqlAsyncTransaction}. */
    public function queryOn(PgsqlAsyncConnection $connection, string $sql): SqlResult
    {
        $this->assertOpen();
        $this->setNonBlocking($connection, $sql);

        $sent = @\pg_send_query($connection->handle, $sql);

        if ($sent === false) {
            throw $this->dispatchFailure($connection, $sql);
        }

        return $this->await($connection, $sql, $sent === true);
    }

    /**
     * @internal Also used by {@see PgsqlAsyncTransaction}, whose own
     *     pre-flight has already settled $query.
     */
    public function executeOn(PgsqlAsyncConnection $connection, PreflightedQuery $query): SqlResult
    {
        $this->assertOpen();
        $this->setNonBlocking($connection, $query->sql);

        $rewritten = SqlParamInterpolator::render(
            $query,
            static fn (null|bool|int|float|string $value, int $index): string => '$' . ($index + 1),
        );

        // pg_send_query_params() carries the values alongside the query
        // rather than inside the rewritten text, so this is where they
        // are encoded — every one of them already held to the value
        // contract, which is why these arms are the whole set.
        $encoded = \array_map(static fn (null|bool|int|float|string $value): ?string => match (true) {
            $value === null => null,
            \is_bool($value) => $value ? 't' : 'f',
            default => (string) $value,
        }, $query->values);

        $sent = @\pg_send_query_params($connection->handle, $rewritten, $encoded);

        if ($sent === false) {
            throw $this->dispatchFailure($connection, $query->sql);
        }

        return $this->await($connection, $query->sql, $sent === true);
    }

    /**
     * The option reads inverted here: ext-pgsql maps the exported
     * socket's blocking flag straight onto PQsetnonblocking(), so
     * stream_set_blocking($socket, true) is how "put libpq in
     * nonblocking mode" is spelled.
     *
     * Only in that mode does libpq queue output rather than block on it,
     * and pg_connection_busy() — which draining a result runs — puts it
     * back into blocking mode, where the next pg_send_query*() flushes
     * the whole statement synchronously and stalls every Fiber on the
     * worker thread behind one large parameter. Hence before every
     * dispatch rather than once at connect time.
     *
     * It refuses on a connection libpq has already given up on, which is
     * a dispatch-phase failure like any other: nothing was sent.
     */
    private function setNonBlocking(PgsqlAsyncConnection $connection, string $sql): void
    {
        if (\stream_set_blocking($connection->socket, true)) {
            return;
        }

        throw $this->dispatchFailure($connection, $sql);
    }

    /**
     * Suspends the calling Fiber until the statement's result arrives.
     * $flushed is what dispatch reported: true when libpq pushed the
     * whole statement out, so results can be read straight away, and
     * false when bytes are still queued.
     *
     * Queued output is waited on in both directions, which is libpq's
     * own rule for it: readable and writable both continue the flush,
     * and a readable socket has input to consume first. The server
     * talks back while a statement is still going out — NOTICE and
     * NOTIFY traffic reaches a client mid-dispatch — and a client
     * watching only for writability never drains it, so both socket
     * buffers fill and neither side can move.
     */
    private function await(PgsqlAsyncConnection $connection, string $sql, bool $flushed): SqlResult
    {
        $connection->protocolBusy = true;
        $connection->sql = $sql;
        $connection->flushing = !$flushed;
        $connection->suspension = EventLoop::getSuspension();

        EventLoop::enable($connection->readableId);

        if (!$flushed) {
            EventLoop::enable($connection->writableId);
        }

        /** @var SqlResult */
        return $connection->suspension->suspend();
    }

    private function dispatchFailure(PgsqlAsyncConnection $connection, string $sql): Throwable
    {
        $message = \pg_last_error($connection->handle) ?: 'Failed to dispatch query';

        if (\pg_connection_status($connection->handle) !== \PGSQL_CONNECTION_OK) {
            $connection->broken = true;

            return new StaleConnectionException("Postgres connection lost during dispatch: {$message}");
        }

        // The connection is healthy and the statement never left the
        // client; only a lost connection is worth discarding.
        return new QueryException($message, $sql);
    }

    /**
     * The onWritable callback for one connection: push out what libpq
     * still has queued, one writable event at a time. A statement whose
     * parameters outgrow libpq's output buffer costs several callbacks
     * here rather than one blocking flush.
     */
    private function onWritable(PgsqlAsyncConnection $connection): void
    {
        // Both watchers can be queued for the same loop turn, so this
        // can run after the readable one already finished the flush, or
        // settled the connection outright. Either way nothing is left
        // here to push out.
        if (!$connection->flushing) {
            return;
        }

        if ($this->flushOut($connection)) {
            $this->drain($connection);
        }
    }

    /**
     * The onReadable callback for one connection: consume input, then —
     * with output still queued — flush again, or else take whichever
     * results libpq has finished parsing.
     *
     * Consuming first is what keeps the reverse direction moving while
     * a large statement is still going out. Draining waits: reading a
     * result is what pg_get_result() blocks on, and libpq counts a
     * statement it has not finished sending as one whose result has not
     * arrived.
     */
    private function onReadable(PgsqlAsyncConnection $connection): void
    {
        if ($connection->suspension === null) {
            return;
        }

        if (!\pg_consume_input($connection->handle)) {
            $this->loseConnection($connection);

            return;
        }

        // The consume above may have been the last readiness this
        // socket reports, so a flush that finishes here goes straight
        // on to whatever libpq has already parsed rather than waiting
        // for an edge that never comes.
        if ($connection->flushing && !$this->flushOut($connection)) {
            return;
        }

        $this->drain($connection);
    }

    /**
     * Flushes what libpq still has queued for the statement in flight
     * and reports whether the whole statement is now out. pg_flush()
     * answers 0 while output remains, true once it is all gone, and
     * false when the connection failed under it — the one case that
     * settles the waiter here.
     */
    private function flushOut(PgsqlAsyncConnection $connection): bool
    {
        $flushed = \pg_flush($connection->handle);

        if ($flushed === false) {
            $this->loseConnection($connection);

            return false;
        }

        if ($flushed === 0) {
            return false;
        }

        $connection->flushing = false;
        EventLoop::disable($connection->writableId);

        return true;
    }

    /**
     * Takes whichever results libpq has finished parsing. It returns
     * without settling anything while more are outstanding, so a query
     * whose results arrive in several pieces costs several callbacks
     * rather than one blocking wait — pg_get_result() blocks whenever
     * the next result is not parsed yet, and blocking here would stall
     * every other Fiber on the worker thread.
     */
    private function drain(PgsqlAsyncConnection $connection): void
    {
        // Suppressed: on a connection the server has already torn down,
        // libpq emits a "cannot set connection to blocking mode" notice
        // here before the code below settles the waiter with a real
        // exception. Same @-plus-clean-exception handling pg_connect()
        // and mysqli's real_connect() already get.
        while (!@\pg_connection_busy($connection->handle)) {
            $result = \pg_get_result($connection->handle);

            if ($result === false) {
                // Everything this query produced has been read, so the
                // connection is drained and reusable whatever the answer
                // is.
                $connection->protocolBusy = false;
                $this->settleDrained($connection);

                return;
            }

            if (!$this->record($connection, $result)) {
                return;
            }
        }
    }

    /**
     * Settles the waiter for a connection that failed under a consume
     * or a flush. Marking it broken is what makes the pooled release
     * abort it rather than hand it to the next caller.
     */
    private function loseConnection(PgsqlAsyncConnection $connection): void
    {
        $connection->broken = true;
        $connection->resetResults();
        $this->settle($connection, null, new ConnectionException(
            'Postgres connection error: ' . \pg_last_error($connection->handle),
        ));
    }

    /**
     * Files one drained result against the connection. Returns false
     * when the drain has been abandoned and the waiter already settled.
     */
    private function record(PgsqlAsyncConnection $connection, Result $result): bool
    {
        $status = \pg_result_status($result);

        if ($status === \PGSQL_COPY_IN || $status === \PGSQL_COPY_OUT) {
            // COPY switches the connection into a streaming mode this
            // client has no protocol for, and the server holds it there
            // until data it is never going to get arrives. Reading
            // further results would wait for exactly that, so the
            // connection is taken out of service where it stands: the
            // exchange is lost, not the statement refused.
            $this->abort($connection, new ConnectionException(self::COPY_MESSAGE));

            return false;
        }

        $connection->resultCount++;

        if ($status === \PGSQL_FATAL_ERROR || $status === \PGSQL_BAD_RESPONSE) {
            $connection->resultError ??= new QueryException(
                \pg_result_error($result) ?: 'Query failed',
                $connection->sql,
            );

            return true;
        }

        $connection->firstResult ??= $result;

        return true;
    }

    /** Settles the waiter once the connection holds no further results. */
    private function settleDrained(PgsqlAsyncConnection $connection): void
    {
        $error = $connection->resultError;
        $first = $connection->firstResult;
        $count = $connection->resultCount;
        $sql = $connection->sql;
        $connection->resetResults();

        if ($error !== null) {
            $this->settle($connection, null, $error);

            return;
        }

        if ($count > 1) {
            $this->settle($connection, null, new QueryException(\sprintf(
                'One statement per call: the query produced %d result sets.',
                $count,
            ), $sql));

            return;
        }

        if ($first === null) {
            $this->settle($connection, null, new QueryException('Query produced no result', $sql));

            return;
        }

        $this->settle($connection, $this->buildResult($first), null);
    }

    private function settle(PgsqlAsyncConnection $connection, ?SqlResult $result, ?Throwable $error): void
    {
        EventLoop::disable($connection->readableId);
        EventLoop::disable($connection->writableId);
        $connection->flushing = false;

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

            // Attempts count against the pool as much as finished
            // connections do: opening suspends, so without them every
            // Fiber that found the pool short would start one of its own
            // and the pool would overshoot maxConnections.
            if (\count($this->connections) + \count($this->opening) < $this->options->maxConnections) {
                return $this->open();
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
    public function release(PgsqlAsyncConnection $connection, bool $discard = false): void
    {
        if ($discard || $connection->broken || $connection->protocolBusy || $this->closed) {
            $this->abort($connection);

            return;
        }

        if (!$this->waiters->isEmpty()) {
            $this->waiters->dequeue()->resume($connection);

            return;
        }

        $this->idle[] = $connection;
    }

    /**
     * Takes one connection out of service for good: the pool forgets it,
     * the transport goes, whatever was in flight on it is settled, and
     * one Fiber waiting for a connection is woken to open a replacement.
     * The single way out for a connection that must not be reused —
     * discarded by a transaction closing from another Fiber, broken
     * under a statement, put into COPY, or held by a client being
     * closed. $failure is the answer the waiting Fiber gets.
     *
     * A connection the pool has already forgotten passes through
     * untouched, so an owner resuming into its own terminal transition
     * settles nothing twice — and closing a libpq handle twice is an
     * error, not a no-op.
     */
    private function abort(PgsqlAsyncConnection $connection, ?Throwable $failure = null): void
    {
        $id = \spl_object_id($connection);

        if (!isset($this->connections[$id])) {
            return;
        }

        unset($this->connections[$id]);
        $this->idle = \array_values(\array_filter(
            $this->idle,
            static fn (PgsqlAsyncConnection $pooled): bool => $pooled !== $connection,
        ));

        EventLoop::cancel($connection->readableId);
        EventLoop::cancel($connection->writableId);
        $suspension = $connection->suspension;
        $connection->suspension = null;
        $connection->broken = true;

        // pg_close() reads every outstanding result before it returns,
        // so an exchange in flight is ended first and the close below
        // finds a connection libpq has already given up on.
        if ($connection->protocolBusy) {
            $this->endTransport($connection);
        }

        $connection->protocolBusy = false;
        $connection->flushing = false;
        $connection->resetResults();
        \pg_close($connection->handle);

        // Settled after the handle is gone, so the Fiber resuming here
        // can never reach one this method is about to close.
        $suspension?->throw($failure ?? new ConnectionException(self::ABORTED_MESSAGE));

        $this->wakeWaiter();
    }

    /**
     * Ends the transport under libpq, so closing the handle has nothing
     * left to wait for. ext-pgsql closes a connection by reading every
     * outstanding result first, which on a statement still running — or
     * a COPY the server is waiting on input for — is a blocking wait the
     * whole event loop pays, and in the COPY case one with no end.
     * Shutting the socket down in both directions makes every further
     * read end in EOF instead, which is what drives libpq to report the
     * connection bad without waiting on a server. The server loses its
     * client and ends the statement.
     *
     * A status check, a shutdown and one read, with no loop anywhere. A
     * connection libpq has already given up on skips the last two: it
     * holds nothing a close could wait for, and its descriptor is no
     * longer libpq's to shut down.
     *
     * Nothing here reaches the socket after pg_close(): the imported
     * descriptor and the handle are the same descriptor, and the caller
     * closes the handle immediately after this returns.
     */
    private function endTransport(PgsqlAsyncConnection $connection): void
    {
        if (\pg_connection_status($connection->handle) !== \PGSQL_CONNECTION_OK) {
            return;
        }

        @\socket_shutdown($connection->transport, self::SHUT_RDWR);

        // One read, not a loop: pg_consume_input() answering true says
        // that nothing failed, not that anything arrived, so looping on
        // it spins for as long as a live connection has nothing to say.
        // Bounding this needs no loop anyway — the shutdown above is
        // what makes every read from here on report EOF, and this one
        // hands libpq that EOF, so the close that follows finds a
        // connection it already knows is gone.
        @\pg_consume_input($connection->handle);
    }

    /**
     * Opens one connection against a pool slot held for the whole
     * attempt. A failed attempt frees the slot and wakes one waiting
     * Fiber to try again on it.
     */
    private function open(): PgsqlAsyncConnection
    {
        $handle = @\pg_connect($this->connectionString(), \PGSQL_CONNECT_FORCE_NEW | \PGSQL_CONNECT_ASYNC);

        if ($handle === false) {
            $this->wakeWaiter();

            throw new ConnectionException('Failed to connect to Postgres');
        }

        $id = $this->attempts++;
        $this->opening[$id] = [$handle, null];

        try {
            $connection = $this->establish($id, $handle);
        } catch (Throwable $e) {
            // A dropped entry means close() already took the handle.
            if (isset($this->opening[$id])) {
                unset($this->opening[$id]);
                \pg_close($handle);
            }

            $this->wakeWaiter();

            throw $e;
        }

        unset($this->opening[$id]);
        $this->connections[\spl_object_id($connection)] = $connection;

        return $connection;
    }

    /**
     * Drives PGSQL_CONNECT_ASYNC's handshake to completion, waiting on
     * the event loop between polls, and arms the finished connection's
     * two watchers.
     */
    private function establish(int $id, Connection $handle): PgsqlAsyncConnection
    {
        $socket = \pg_socket($handle);

        if ($socket === false) {
            throw new ConnectionException('Failed to export the Postgres connection socket');
        }

        $deadline = $this->options->connectTimeout !== null
            ? \microtime(true) + $this->options->connectTimeout
            : null;

        while (($status = \pg_connect_poll($handle)) !== \PGSQL_POLLING_OK) {
            if ($status === \PGSQL_POLLING_FAILED) {
                throw new ConnectionException('Failed to connect to Postgres');
            }

            $this->awaitHandshake($id, $socket, $status === \PGSQL_POLLING_READING, $deadline);

            if (!isset($this->opening[$id])) {
                throw new ConnectionException(self::CLOSED_MESSAGE);
            }
        }

        // Only now is there one descriptor to name. libpq closes its
        // socket and opens another for the next address a host resolved
        // to, and socket_import_stream() captures whichever descriptor
        // the stream resolves to at that moment — the stream itself
        // resolves libpq's current one on every use, so importing it
        // before the handshake settled could hand disposal a socket the
        // finished connection never runs on.
        $transport = \socket_import_stream($socket);

        if (!$transport instanceof Socket) {
            throw new ConnectionException('Failed to import the Postgres connection socket');
        }

        $connection = new PgsqlAsyncConnection($handle, $socket, $transport);
        $connection->readableId = EventLoop::disable(EventLoop::onReadable($socket, function () use ($connection): void {
            $this->onReadable($connection);
        }));
        $connection->writableId = EventLoop::disable(EventLoop::onWritable($socket, function () use ($connection): void {
            $this->onWritable($connection);
        }));

        return $connection;
    }

    /**
     * Suspends until libpq's socket is ready in the direction
     * pg_connect_poll() asked for. A fresh watcher per wait, because the
     * direction alternates as the handshake proceeds and TLS
     * negotiation crosses back and forth several times.
     *
     * `DB_CONNECT_TIMEOUT` bounds the wait. Without it the only bound is
     * the platform's own, exactly as for the blocking PDO drivers.
     *
     * @param resource $socket
     */
    private function awaitHandshake(int $id, mixed $socket, bool $reading, ?float $deadline): void
    {
        $suspension = EventLoop::getSuspension();
        /** @var list<string> $watchers */
        $watchers = [];
        // Readiness, the deadline and close() all settle this wait, and
        // whichever gets there first cancels the others — resuming a
        // Suspension twice is an error, not a no-op.
        $wake = static function (?Throwable $error) use ($suspension, &$watchers): void {
            if ($watchers === []) {
                return;
            }

            foreach ($watchers as $watcher) {
                EventLoop::cancel($watcher);
            }

            $watchers = [];
            $error === null ? $suspension->resume() : $suspension->throw($error);
        };

        $watchers[] = $reading
            ? EventLoop::onReadable($socket, static fn () => $wake(null))
            : EventLoop::onWritable($socket, static fn () => $wake(null));

        if ($deadline !== null) {
            $watchers[] = EventLoop::delay(
                \max(0.0, $deadline - \microtime(true)),
                static fn () => $wake(new ConnectionException('Timed out connecting to Postgres')),
            );
        }

        $this->opening[$id][1] = $wake;

        try {
            $suspension->suspend();
        } finally {
            if (isset($this->opening[$id])) {
                $this->opening[$id][1] = null;
            }
        }
    }

    private function wakeWaiter(): void
    {
        if (!$this->waiters->isEmpty()) {
            $this->waiters->dequeue()->resume(null);
        }
    }

    /** The libpq connection string this client's options translate to. */
    private function connectionString(): string
    {
        $quote = LibpqValue::quote(...);

        // FORCE_NEW: pg_connect() otherwise silently reuses one shared
        // handle for identical connection strings, collapsing the pool
        // to a single real connection.
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

        if ($this->options->sslCert !== null) {
            $connectionString .= ' sslcert=' . $quote($this->options->sslCert);
            $connectionString .= ' sslkey=' . $quote((string) $this->options->sslKey);
        }

        if ($this->options->connectTimeout !== null) {
            $connectionString .= " connect_timeout={$this->options->connectTimeout}";
        }

        if ($this->options->applicationName !== null) {
            $connectionString .= ' application_name=' . $quote($this->options->applicationName);
        }

        return $connectionString;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new ConnectionException(self::CLOSED_MESSAGE);
        }
    }
}
