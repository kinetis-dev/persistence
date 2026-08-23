<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;
use Kinetis\Persistence\TransactionGuard;

/**
 * The MCP counterpart of DanglingTransactionController: a tool that
 * begins a transaction and never closes it, proving each transport's
 * per-message TransactionGuard hook is what actually rolls it back.
 */
final readonly class DanglingTransactionToolController
{
    public function __construct(
        private TransactionGuard $guard,
    ) {}

    /**
     * @return array{started: bool}
     */
    #[McpTool(name: 'begin_transaction', description: 'Opens a transaction and leaves it open')]
    public function begin(): array
    {
        $link = new FakeSqlLink();
        $this->guard->beginTransaction($link);

        DanglingTransactionHolder::$link = $link;

        return ['started' => true];
    }
}
