<?php

declare(strict_types=1);

namespace Kinetis\Persistence;

use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Contract\SqlTransaction;
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

            return $result;
        } catch (Throwable $e) {
            if ($transaction->isActive()) {
                $transaction->rollback();
            }

            throw $e;
        }
    }

    /**
     * Rolls back any transaction this guard started that's still active
     * when the request ends. Safe to call unconditionally — logs a
     * warning only when it actually finds one to roll back, since this
     * runs on every request regardless of whether one was ever opened and
     * the overwhelming majority of calls are a genuine no-op.
     */
    public function rollbackDangling(): void
    {
        foreach ($this->open as $transaction) {
            if ($transaction->isActive()) {
                $this->logger->warning(
                    'Rolled back a transaction that was still open when the request ended.',
                );
                $transaction->rollback();
            }
        }

        $this->open = [];
    }
}
