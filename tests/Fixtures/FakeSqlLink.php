<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Fixtures;

use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Contract\SqlTransaction;
use LogicException;

final class FakeSqlLink implements SqlLink
{
    /** @var list<FakeSqlTransaction> */
    public array $transactions = [];

    public function beginTransaction(): SqlTransaction
    {
        $transaction = new FakeSqlTransaction();
        $this->transactions[] = $transaction;

        return $transaction;
    }

    public function query(string $sql): SqlResult
    {
        throw new LogicException('Not needed by TransactionGuard.');
    }

    public function execute(string $sql, array $params = []): SqlResult
    {
        throw new LogicException('Not needed by TransactionGuard.');
    }

    public function close(): void
    {
    }

    public function isClosed(): bool
    {
        return false;
    }
}
