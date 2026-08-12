<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Fixtures;

use Kinetis\Http\Attributes\Post;
use Kinetis\Persistence\TransactionGuard;

/**
 * Deliberately begins a transaction and never commits or rolls it back —
 * proving Kernel's TransactionGuard::rollbackDangling() dispose hook is
 * what actually closes it, not the controller.
 */
final readonly class DanglingTransactionController
{
    public function __construct(
        private TransactionGuard $guard,
    ) {}

    #[Post('/begin-transaction')]
    public function begin(): array
    {
        $link = new FakeSqlLink();
        $this->guard->beginTransaction($link);

        DanglingTransactionHolder::$link = $link;

        return ['started' => true];
    }
}
