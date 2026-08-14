<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Contract\SqlTransaction;
use Kinetis\Persistence\Exception\TransactionException;

/**
 * The transaction state machine every driver shares: active until
 * exactly one commit() or rollback(), close() rolls back a still-active
 * transaction (the TransactionGuard::rollbackDangling() contract), and
 * operating on a finished transaction throws. Subclasses supply only
 * how SQL actually reaches their pinned connection ({@see run()}) and
 * what finishing does with it ({@see finish()}).
 *
 * @internal
 */
abstract class AbstractTransaction implements SqlTransaction
{
    private bool $active = true;

    public function query(string $sql): SqlResult
    {
        $this->assertActive();

        return $this->run($sql);
    }

    public function execute(string $sql, array $params = []): SqlResult
    {
        $this->assertActive();

        return $this->runWithParams($sql, $params);
    }

    public function beginTransaction(): SqlTransaction
    {
        throw new TransactionException('Nested transactions are not supported by ' . $this->driverLabel());
    }

    public function commit(): void
    {
        $this->assertActive();
        $this->active = false;
        $this->finish(true);
    }

    public function rollback(): void
    {
        $this->assertActive();
        $this->active = false;
        $this->finish(false);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function close(): void
    {
        if ($this->active) {
            $this->rollback();
        }
    }

    public function isClosed(): bool
    {
        return !$this->active;
    }

    /** Executes complete SQL text on the pinned connection. */
    abstract protected function run(string $sql): SqlResult;

    /**
     * Executes SQL with "?" placeholders on the pinned connection.
     *
     * @param array<int|string, mixed> $params
     */
    abstract protected function runWithParams(string $sql, array $params): SqlResult;

    /**
     * Completes the transaction on the pinned connection — COMMIT or
     * ROLLBACK plus whatever handing the connection back requires.
     * Called exactly once, after the transaction is already marked
     * inactive (so a failing finish still leaves it closed).
     */
    abstract protected function finish(bool $commit): void;

    /** Names the driver in the nested-transaction error. */
    abstract protected function driverLabel(): string;

    private function assertActive(): void
    {
        if (!$this->active) {
            throw new TransactionException('The transaction has already been committed or rolled back');
        }
    }
}
