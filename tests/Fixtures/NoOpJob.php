<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Fixtures;

use Kinetis\Queue\Job;

/**
 * Never resolves TransactionGuard at all — proving
 * Kinetis\Container\TransactionGuardHook's dispose-hook registration is a
 * genuine no-op for a job that never touches persistence, not just
 * something that happens not to crash.
 */
final readonly class NoOpJob implements Job
{
    public function handle(): void
    {
    }
}
