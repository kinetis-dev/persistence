<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Kinetis\Persistence\Contract\SqlResult;
use PgSql\Connection;
use PgSql\Result;
use Revolt\EventLoop;
use Socket;
use Throwable;

/**
 * Per-connection state for {@see PgsqlAsyncClient}: the libpq handle, its
 * exported socket and the ext-sockets view of that same descriptor, the
 * two Revolt watchers created (disabled) at connect time, the Suspension
 * of whichever Fiber is currently awaiting a result on it, and what the
 * statement in flight has produced so far.
 *
 * Two watchers, because a statement moves in two directions. A parameter
 * larger than libpq's output buffer leaves bytes queued after dispatch,
 * and both watchers run until it is gone ({@see $flushing}); once it is,
 * the readable watcher alone drains the results. Draining spans several
 * callbacks of its own: pg_get_result() may only be called while libpq
 * is not busy, so a result libpq has not finished parsing is waited for
 * instead of blocked on.
 *
 * @internal
 */
final class PgsqlAsyncConnection
{
    /** @var EventLoop\Suspension<SqlResult>|null */
    public ?EventLoop\Suspension $suspension = null;

    public bool $broken = false;

    /**
     * Whether libpq may still be mid-exchange on this connection: a
     * statement dispatched and not yet drained, or a COPY the server is
     * streaming. Closing such a connection means reading every
     * outstanding result first, which is a blocking wait — see
     * {@see PgsqlAsyncClient} for what it takes to avoid it.
     */
    public bool $protocolBusy = false;

    /**
     * Whether libpq still holds output for the statement in flight.
     * While it does, the connection is waited on in both directions:
     * libpq's rule with output queued is to wait for the socket to
     * become readable *or* writable, consuming input on a readable one
     * before flushing again. The server can send NOTICE or NOTIFY
     * traffic while the client still has a statement to push out, and a
     * client that never drains it fills both socket buffers, leaving
     * neither side able to move.
     */
    public bool $flushing = false;

    /** The statement in flight, so a result error reaches the caller carrying its SQL. */
    public string $sql = '';

    /** The first result of the statement being drained — the only one a call may produce. */
    public ?Result $firstResult = null;

    /** How many results the statement being drained has produced, errors included. */
    public int $resultCount = 0;

    /** The first error the statement being drained reported. */
    public ?Throwable $resultError = null;

    /** Assigned immediately after construction, once the watcher closures can reference this instance. */
    public string $readableId = '';

    public string $writableId = '';

    /**
     * @param resource $socket libpq's own descriptor, exported by
     *     pg_socket() — what the watchers above watch, and what
     *     stream_set_blocking() reaches PQsetnonblocking() through.
     * @param Socket $transport The same descriptor as ext-sockets sees
     *     it, imported once the handshake settled so disposal can shut
     *     the transport down. Importing it before this connection is
     *     published rather than at disposal is what makes the driver fail
     *     closed: a connection it could not take out of service without
     *     blocking never enters the pool.
     */
    public function __construct(
        public readonly Connection $handle,
        public readonly mixed $socket,
        public readonly Socket $transport,
    ) {}

    /** Clears the drain state, so the next statement on this connection starts from nothing. */
    public function resetResults(): void
    {
        $this->firstResult = null;
        $this->resultCount = 0;
        $this->resultError = null;
        $this->sql = '';
    }
}
