<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Contract;

/**
 * An open transaction: the same execution surface as SqlLink, pinned to
 * one connection, finished by exactly one commit() or rollback().
 * close() on a still-active transaction rolls back — the safety-net
 * behavior TransactionGuard::rollbackDangling() relies on.
 */
interface SqlTransaction extends SqlLink
{
    public function commit(): void;

    public function rollback(): void;

    public function isActive(): bool;
}
