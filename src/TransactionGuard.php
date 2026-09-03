<?php

declare(strict_types=1);

namespace Kinetis\Persistence;

use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Contract\SqlTransaction;
use Kinetis\Persistence\Exception\TransactionException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The request-lifecycle safety net for SQL transactions. The drivers
 * themselves handle connection pooling and dead-connection recycling;
 * what no driver can know about is Kinetis's `RequestScope`: if
 * application code begins a transaction and something throws before it
 * commits or rolls back, nothing closes it, and it leaks into whatever
 * that pooled connection is used for next.
 *
 * `TransactionGuard` is request-scoped — autowired fresh per
 * `RequestScope` like any other unregistered class — and tracks every
 * transaction it starts so `rollbackDangling()` can close anything still
 * open when the request ends. `Kernel` wires this into
 * `RequestScope::onDispose()` unconditionally; it's a no-op for requests
 * that never touch a database.
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
     * rollback() before the request ends.
     *
     * A transaction this method closes — by commit, or by attempting a
     * rollback on the way out — is untracked immediately, always, whether
     * or not that closing attempt actually succeeded; tracking only ever
     * needs to represent genuinely outstanding work, and this method only
     * ever makes one cleanup attempt of its own. Leaving a failed attempt
     * tracked would defer a second one to rollbackDangling() at scope
     * disposal — a `finally` block whose own throw would silently replace
     * whatever exception is already propagating, exactly the
     * failure-erasing bug this method exists to avoid one level up
     * (`$e`, the failure that triggered cleanup in the first place, is
     * always what's thrown here — a rollback failure while handling it is
     * logged, never thrown in its place, and never left for a later,
     * higher-level dispose hook to rediscover and re-throw instead).
     *
     * @template T
     * @param callable(SqlTransaction): T $callback
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
                if ($transaction->isActive()) {
                    $transaction->rollback();
                }
            } catch (Throwable $cleanupFailure) {
                $this->logSafely(
                    'error',
                    'Failed to roll back a transaction while handling a prior failure.',
                    ['exception' => $cleanupFailure],
                );
            } finally {
                $this->untrack($transaction);
            }

            throw $e;
        }
    }

    /**
     * Rolls back every transaction this guard started that's still
     * active when the request ends — best-effort across the complete
     * tracked set, not fail-fast: one transaction's `isActive()` or
     * `rollback()` throwing never prevents the rest from being attempted,
     * since on a persistent worker a cleanup fault on one connection must
     * not leak transactions/locks on every other tracked one.
     *
     * Tracking is cleared before any transaction is touched, not after —
     * so a transaction this call already attempted (successfully or not)
     * is never retried by a later call, matching a plain, single-attempt
     * best-effort contract rather than an open-ended retry loop.
     *
     * The warning for a transaction actually closed is logged only once
     * `rollback()` has genuinely succeeded, never before — reporting
     * success ahead of the call would misreport a failed rollback as one
     * that worked. The logging call itself sits outside the try/catch
     * that classifies success vs. failure: a logger that throws while
     * reporting a genuine success must never be misclassified as a
     * rollback failure, and a logger that throws while reporting a
     * genuine failure must never prevent a later tracked transaction from
     * being attempted — see logSafely()'s own docblock. Each rollback
     * failure is logged individually (so nothing is lost even when
     * several fail at once), and if any failed, a single
     * TransactionException is thrown after every transaction has been
     * attempted, carrying the first failure as its cause — safe to let
     * propagate: RequestScope::dispose() already runs every dispose
     * callback to completion regardless of one throwing, and rethrows
     * only once all of them (and the scope wipe) have finished.
     */
    public function rollbackDangling(): void
    {
        $pending = $this->open;
        $this->open = [];

        /** @var list<Throwable> $failures */
        $failures = [];

        foreach ($pending as $transaction) {
            try {
                if (!$transaction->isActive()) {
                    continue;
                }

                $transaction->rollback();
            } catch (Throwable $e) {
                $this->logSafely(
                    'error',
                    'Failed to roll back a transaction that was still open when the request ended.',
                    ['exception' => $e],
                );
                $failures[] = $e;

                continue;
            }

            $this->logSafely(
                'warning',
                'Rolled back a transaction that was still open when the request ended.',
            );
        }

        if ($failures !== []) {
            throw new TransactionException(
                sprintf('Failed to roll back %d dangling transaction(s) — see the logged errors for detail.', count($failures)),
                0,
                $failures[0],
            );
        }
    }

    /**
     * Removes a transaction this guard is no longer responsible for —
     * closed by transaction() itself, or already rolled back by
     * rollbackDangling(). Identity comparison, not value comparison:
     * SqlTransaction carries no natural key of its own.
     */
    private function untrack(SqlTransaction $transaction): void
    {
        $this->open = array_values(array_filter(
            $this->open,
            static fn (SqlTransaction $tracked): bool => $tracked !== $transaction,
        ));
    }

    /**
     * This class's actual safety-net contract — every tracked transaction
     * gets an independent cleanup attempt, and a failure that triggered
     * cleanup is never silently replaced by a secondary one — must hold
     * regardless of whether the configured logger itself is healthy.
     * `Psr\Log\LoggerInterface` gives no no-throw guarantee, and a
     * failing log handler (a broken remote sink, a full disk) is a real
     * production scenario, not a theoretical one. Any exception the
     * logger itself throws is discarded here rather than allowed to
     * interrupt cleanup, misclassify an already-succeeded rollback as
     * failed, or replace whatever failure is already being reported.
     *
     * @param 'warning'|'error' $level
     * @param array<string, mixed> $context
     */
    private function logSafely(string $level, string $message, array $context = []): void
    {
        try {
            match ($level) {
                'warning' => $this->logger->warning($message, $context),
                'error' => $this->logger->error($message, $context),
            };
        } catch (Throwable) {
            // Discarded deliberately — see this method's own docblock.
        }
    }
}
