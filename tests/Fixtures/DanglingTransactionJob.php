<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Fixtures;

use Kinetis\Persistence\TransactionGuard;
use Kinetis\Queue\Job;

/**
 * Deliberately begins a transaction and never commits or rolls it back,
 * then returns normally — proving Kinetis\Container\TransactionGuardHook's
 * dispose hook, wired by QueueWorker/SyncQueue against each job's own
 * RequestScope, is what actually closes it, not the job itself. The HTTP
 * equivalent of this fixture is DanglingTransactionController.
 */
final readonly class DanglingTransactionJob implements Job
{
    public function handle(TransactionGuard $guard): void
    {
        $link = new FakeSqlLink();
        $guard->beginTransaction($link);

        DanglingTransactionHolder::$link = $link;
    }
}
