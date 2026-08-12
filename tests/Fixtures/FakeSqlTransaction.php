<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Fixtures;

use Amp\Sql\SqlResult;
use Amp\Sql\SqlStatement;
use Amp\Sql\SqlTransaction;
use Amp\Sql\SqlTransactionIsolation;
use Amp\Sql\SqlTransactionIsolationLevel;
use Closure;
use LogicException;

/**
 * A minimal real implementation of Amp\Sql\SqlTransaction for testing
 * TransactionGuard without a real database — only isActive()/commit()/
 * rollback() (the methods the guard actually calls) do anything
 * meaningful; everything else this interface requires but the guard
 * never touches throws, so a test would fail loudly if that assumption
 * ever stopped holding.
 */
final class FakeSqlTransaction implements SqlTransaction
{
    public bool $active = true;

    public bool $committed = false;

    public bool $rolledBack = false;

    public function isActive(): bool
    {
        return $this->active;
    }

    public function commit(): void
    {
        $this->active = false;
        $this->committed = true;
    }

    public function rollback(): void
    {
        $this->active = false;
        $this->rolledBack = true;
    }

    public function getIsolation(): SqlTransactionIsolation
    {
        return SqlTransactionIsolationLevel::Committed;
    }

    public function getSavepointIdentifier(): ?string
    {
        return null;
    }

    public function onCommit(Closure $onCommit): void
    {
        throw new LogicException('Not needed by TransactionGuard.');
    }

    public function onRollback(Closure $onRollback): void
    {
        throw new LogicException('Not needed by TransactionGuard.');
    }

    public function query(string $sql): SqlResult
    {
        throw new LogicException('Not needed by TransactionGuard.');
    }

    public function prepare(string $sql): SqlStatement
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
        $this->active = false;
    }

    public function isClosed(): bool
    {
        return !$this->active;
    }

    public function onClose(Closure $onClose): void
    {
        throw new LogicException('Not needed by TransactionGuard.');
    }

    public function getLastUsedAt(): int
    {
        return time();
    }
}
