<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Contract;

/**
 * An open transaction: the same execution surface as SqlLink, pinned to
 * one connection, ended exactly once.
 *
 * It belongs to the Fiber that began it. query(), execute(), commit()
 * and rollback() throw Exception\TransactionException from any other
 * Fiber, since the pinned connection carries one statement at a time.
 * close() is the lifecycle escape hatch and is callable from anywhere —
 * it is what TransactionGuard::rollbackDangling() runs at scope
 * disposal, where the owning Fiber may be parked; from a foreign Fiber
 * it ends the transaction and discards the connection rather than
 * sending a concurrent ROLLBACK.
 *
 * A transaction that reaches none of the three ends when its last
 * reference goes away: the connection is discarded, not rolled back on
 * the wire, and the outcome is recorded as unknown. That is a safety
 * net for a connection, not a way to end a transaction — the work is
 * discarded with the session and nothing acknowledged it.
 *
 * rollback() on a transaction that has already ended is a no-op;
 * commit() throws. A COMMIT or ROLLBACK the server refuses throws and
 * discards the connection: what is left on it is a transaction of
 * unknown outcome. On MySQL, DDL commits the surrounding transaction
 * implicitly — see docs/migrations.md — and the native driver cannot
 * observe that, so keep DDL out of transactions.
 */
interface SqlTransaction extends SqlLink
{
    public function commit(): void;

    public function rollback(): void;

    /**
     * Whether the transaction is still open. A driver that can see the
     * server ended it underneath the object answers false and settles it
     * right there — connection handed back, nothing left to close — so
     * the answer never leaves a caller responsible for a transaction it
     * has just been told is over.
     */
    public function isActive(): bool;
}
