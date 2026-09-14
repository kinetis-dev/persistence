<?php

declare(strict_types=1);

namespace Kinetis\Persistence;

use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Contract\SqlTransaction;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The unit-of-work safety net for SQL transactions. The drivers
 * themselves handle connection pooling and dead-connection recycling;
 * what no driver can know about is where the host's unit of work — a
 * request, a job, a command — ends: if application code begins a
 * transaction and something throws before it commits or rolls back,
 * nothing ends it, and it holds its connection — and the locks on it —
 * for as long as anything still references it. A transaction dropped
 * without ever reaching the guard ends the only way a destructor can,
 * discarding its connection with the outcome unknown
 * ({@see Driver\AbstractTransaction::__destruct()}); running the work
 * through the guard is what ends it on the wire and hands the connection
 * back.
 *
 * One guard belongs to one unit of work. It tracks every transaction it
 * starts, and the host calls `rollbackDangling()` when that unit ends to
 * close anything still open — a no-op for a unit that never touched a
 * database. A guard is never shared between units: the transactions it
 * tracks belong to the unit that began them.
 *
 * Works identically for MySQL and Postgres, and for every driver: all
 * implement the shared `Kinetis\Persistence\Contract\SqlLink`/
 * `SqlTransaction` contracts, so this class never needs to know which
 * one it's talking to.
 */
final class TransactionGuard
{
    /** @var list<SqlTransaction> */
    private array $open = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @template TTransaction of SqlTransaction
     * @param SqlLink<TTransaction> $link
     * @return TTransaction
     */
    public function beginTransaction(SqlLink $link): SqlTransaction
    {
        $transaction = $link->beginTransaction();
        $this->open[] = $transaction;

        return $transaction;
    }

    /**
     * The recommended way to use a transaction: commits on success, rolls
     * back on any throw, always closes before returning — so there is
     * nothing left for rollbackDangling() to ever find here. That method
     * exists for the case this one doesn't cover: a transaction held open
     * across multiple calls that never reaches either commit() or
     * rollback() before the unit of work ends.
     *
     * The callback's own failure is what propagates. A rollback that
     * fails while handling it is logged, never thrown in its place, and
     * the transaction is untracked either way — leaving it tracked would
     * defer a second attempt to rollbackDangling() at the end of the unit
     * of work, where a throw would replace the exception already
     * propagating from here.
     *
     * The callback receives the link's own transaction type — see
     * {@see SqlLink}.
     *
     * @template TTransaction of SqlTransaction
     * @template T
     * @param SqlLink<TTransaction> $link
     * @param callable(TTransaction): T $callback
     * @return T
     */
    public function transaction(SqlLink $link, callable $callback): mixed
    {
        $transaction = $this->beginTransaction($link);

        try {
            $result = $callback($transaction);
            $transaction->commit();
            $this->untrack($transaction);

            return $result;
        } catch (Throwable $e) {
            try {
                // A no-op on a transaction that already ended.
                $transaction->rollback();
            } catch (Throwable $cleanupFailure) {
                $this->log('error', 'Failed to roll back a transaction while handling a prior failure.', [
                    'exception' => $cleanupFailure,
                ]);
            } finally {
                $this->untrack($transaction);
            }

            throw $e;
        }
    }

    /**
     * Closes every transaction this guard started that is still open when
     * the unit of work ends — best-effort across the complete tracked
     * set, not fail-fast: on a persistent worker, a cleanup fault on one
     * connection must not leak transactions and locks on every other
     * tracked one.
     *
     * close() rather than rollback(), because the host's cleanup runs in
     * its own context while a leaked transaction's owning Fiber may be
     * parked: a foreign Fiber ends the transaction and discards its
     * connection instead of putting a concurrent ROLLBACK on it
     * ({@see \Kinetis\Persistence\Driver\AbstractTransaction::close()}).
     *
     * Tracking is cleared before any transaction is touched, so a
     * transaction this call already attempted is never retried by a later
     * one. Every tracked transaction is attempted, and the first failure
     * is rethrown once they all have been, for the host to report
     * alongside its other cleanup.
     */
    public function rollbackDangling(): void
    {
        $pending = $this->open;
        $this->open = [];
        $failure = null;

        foreach ($pending as $transaction) {
            try {
                if (!$transaction->isActive()) {
                    continue;
                }

                $transaction->close();
            } catch (Throwable $e) {
                $failure ??= $e;
                $this->log('error', 'Failed to close a transaction that was still open when the unit of work ended.', [
                    'exception' => $e,
                ]);

                continue;
            }

            $this->log('warning', 'Closed a transaction that was still open when the unit of work ended.');
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Removes a transaction this guard is no longer responsible for.
     * Identity comparison: SqlTransaction carries no natural key.
     */
    private function untrack(SqlTransaction $transaction): void
    {
        $this->open = array_values(array_filter(
            $this->open,
            static fn (SqlTransaction $tracked): bool => $tracked !== $transaction,
        ));
    }

    /**
     * Cleanup logging is diagnostic, and `Psr\Log\LoggerInterface`
     * gives no no-throw guarantee: an exception from the logger must
     * never be mistaken for a cleanup failure, stop a later transaction
     * from being attempted, or replace an exception already propagating.
     *
     * @param 'warning'|'error' $level
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        try {
            $this->logger->log($level, $message, $context);
        } catch (Throwable) {
            // Discarded: see this method's docblock.
        }
    }
}
