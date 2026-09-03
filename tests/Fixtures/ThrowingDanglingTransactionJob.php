<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Fixtures;

use Kinetis\Persistence\TransactionGuard;
use Kinetis\Queue\Job;
use RuntimeException;

/**
 * Begins a transaction, never closes it, then throws — proving the
 * dispose hook's rollback runs on the failure path too, not only when a
 * job returns normally.
 */
final readonly class ThrowingDanglingTransactionJob implements Job
{
    public function handle(TransactionGuard $guard): void
    {
        $link = new FakeSqlLink();
        $guard->beginTransaction($link);

        DanglingTransactionHolder::$link = $link;

        throw new RuntimeException('deliberate failure');
    }
}
