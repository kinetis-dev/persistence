<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Contract;

/**
 * Something SQL can be executed against — a driver client (pool) or an
 * in-flight transaction, exactly like the query builder and
 * TransactionGuard expect. Kinetis-owned: every driver in
 * Kinetis\Persistence\Driver implements this, and nothing in the
 * persistence stack references a client library's own types.
 */
interface SqlLink
{
    /**
     * Executes complete SQL text with no parameter binding.
     *
     * One statement per call. Where a driver can observe more than one
     * result set, the call throws Exception\QueryException instead of
     * returning the first, and the rest are drained or the connection
     * discarded: draining them would block the event loop on the async
     * drivers, and leaving them unread poisons the connection for
     * everything that borrows it next. PDO Postgres is the one driver
     * that cannot observe it — libpq runs a semicolon-separated string
     * as a single command and reports only its last result, so such a
     * call there returns that last result and no error. Issue one
     * execute()/query() per statement on every driver.
     */
    public function query(string $sql): SqlResult;

    /**
     * Executes SQL with "?" positional placeholders bound from $params.
     * How binding is realized is the driver's business (server-side
     * parameters, or escaped client-side interpolation where the
     * backend's async mode has no bind step) — the safety contract is
     * identical either way: values never merge into SQL unescaped.
     *
     * $params is a list: one value per "?", keys 0..n-1, in the order
     * the placeholders appear, each of them null, a bool, an int, a
     * finite float or a string. Any other keying, any count other than
     * one argument per placeholder, and any other value kind throw
     * Exception\QueryException on every driver — from a pre-flight that
     * runs before the implementation opens a telemetry span, takes a
     * connection from its pool, opens one, configures it, or prepares
     * anything, so a refused call reaches no server and opens nothing
     * to reach one with.
     *
     * The one-statement-per-call rule on {@see query()} applies here
     * too.
     *
     * @param list<mixed> $params
     */
    public function execute(string $sql, array $params = []): SqlResult;

    /**
     * Begins a transaction pinned to one connection, which the Fiber
     * that called this runs every statement of it through until it
     * commits or rolls back.
     *
     * `Kinetis\Persistence\TransactionGuard::transaction()` is the
     * request-safe route to this: it commits on success, rolls back on
     * any throw and ends the transaction before returning either way,
     * so no path out of the work leaves one open. Calling this directly
     * makes ending it the caller's own job.
     *
     * A transaction nothing ever ends is not left holding its
     * connection — {@see SqlTransaction} for what happens to one that
     * is dropped instead. It costs the connection and the certainty,
     * which is why the guard is the normal route and this is not.
     */
    public function beginTransaction(): SqlTransaction;

    public function close(): void;

    public function isClosed(): bool;
}
