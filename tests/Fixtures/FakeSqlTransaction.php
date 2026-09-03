<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Fixtures;

use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Contract\SqlTransaction;
use LogicException;

final class FakeSqlTransaction implements SqlTransaction
{
    public bool $committed = false;

    public bool $rolledBack = false;

    /** Set to make commit() blow up, exercising the guard's error path. */
    public bool $failOnCommit = false;

    /** Set to make isActive() blow up — a real driver's inspection call can fail too. */
    public bool $failOnIsActive = false;

    /** Set to make rollback() blow up, leaving the transaction's own state uncertain. */
    public bool $failOnRollback = false;

    public function commit(): void
    {
        if ($this->failOnCommit) {
            throw new LogicException('Commit failed.');
        }

        $this->committed = true;
    }

    public function rollback(): void
    {
        if ($this->failOnRollback) {
            throw new LogicException('Rollback failed.');
        }

        $this->rolledBack = true;
    }

    public function isActive(): bool
    {
        if ($this->failOnIsActive) {
            throw new LogicException('isActive() failed.');
        }

        return !$this->committed && !$this->rolledBack;
    }

    public function query(string $sql): SqlResult
    {
        throw new LogicException('Not needed by TransactionGuard.');
    }

    public function execute(string $sql, array $params = []): SqlResult
    {
        throw new LogicException('Not needed by TransactionGuard.');
    }

    public function beginTransaction(): SqlTransaction
    {
        throw new LogicException('Not needed by TransactionGuard.');
    }

    public function close(): void
    {
        if ($this->isActive()) {
            $this->rollback();
        }
    }

    public function isClosed(): bool
    {
        return !$this->isActive();
    }
}
