<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Contract;

use Throwable;

/**
 * The moments a driver reports while it works, for a host to turn into
 * spans, timings, or nothing at all. Handed to a client at construction
 * ({@see \Kinetis\Persistence\SqlConnectionFactory}); a client built
 * without one reports nothing.
 *
 * Moments come in started/ended pairs joined by an opaque token:
 * whatever a started moment returns is handed back to its ended moment,
 * and the driver never inspects it. A started moment that fails hands
 * back null instead.
 *
 * The driver contains every failure raised here, so an implementation
 * cannot change a query's result or failure, a transaction's outcome, or
 * the release of a connection. $system is `mysql` or `postgresql`.
 *
 * Every moment runs inline, on the Fiber issuing the statement, inside
 * the driver's own call. An implementation must be synchronous — it never
 * suspends the Fiber — bounded in time, and free of blocking I/O; anything
 * it exports goes to separately owned, bounded infrastructure it hands the
 * data to.
 *
 * A client keeps its instrumentation for the client's whole lifetime — the
 * process's, under a persistent worker — so an implementation holds no
 * mutable request or unit-of-work state.
 *
 * queryDispatched() receives the complete SQL text. Bound parameter values
 * are never passed, but the text can carry literals and is sensitive: an
 * implementation must not log or export it verbatim.
 */
interface SqlInstrumentation
{
    /**
     * A statement handed to a driver. {@see queryServerStarted()} marks
     * the moment it went to the server — the gap in between is time
     * spent waiting for a free connection.
     */
    public function queryDispatched(string $system, string $sql): mixed;

    /** Fires again when a pooled driver retries on a fresh connection. */
    public function queryServerStarted(mixed $token): void;

    public function queryReaped(mixed $token, ?Throwable $failure): void;

    public function transactionStarted(string $system): mixed;

    /**
     * $outcome is `commit` for a COMMIT the server acknowledged,
     * `rollback` for a ROLLBACK it acknowledged, and `unknown` for
     * everything else — a lost or discarded connection, a finish the
     * server never answered, a transaction ended without sending one,
     * and one the server ended on its own.
     *
     * @param 'commit'|'rollback'|'unknown' $outcome
     */
    public function transactionEnded(mixed $token, string $outcome): void;
}
