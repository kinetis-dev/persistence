<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use Kinetis\Container\AppScope;
use Kinetis\Http\Kernel;
use Kinetis\Http\Routing\Router;
use Kinetis\Mcp\McpDispatcher;
use Kinetis\Mcp\McpRegistry;
use Kinetis\Mcp\McpServer;
use Kinetis\Mcp\Transport\StdioTransport;
use Kinetis\Persistence\Tests\Fixtures\DanglingTransactionController;
use Kinetis\Persistence\Tests\Fixtures\DanglingTransactionHolder;
use Kinetis\Persistence\Tests\Fixtures\DanglingTransactionToolController;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * The "kinetis/persistence is actually installed" half of Kernel's
 * TransactionGuard wiring — the counterpart to core's own
 * KernelTest::test_handles_a_request_normally_when_the_persistence_package_is_not_installed().
 * Only this package has both Kernel and TransactionGuard simultaneously
 * available (it depends on kinetis/framework; core never depends the other
 * way), so this is the one place the real dispose-hook wiring can be
 * proven end-to-end.
 */
final class KernelIntegrationTest extends TestCase
{
    public function test_rolls_back_a_transaction_left_open_by_the_controller(): void
    {
        self::assertTrue(class_exists('Kinetis\Persistence\TransactionGuard'));

        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $router->register(DanglingTransactionController::class);

        DanglingTransactionHolder::$link = null;

        $response = (new Kernel($app, $router))->handle(new ServerRequest('POST', '/begin-transaction'));

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull(DanglingTransactionHolder::$link);
        self::assertTrue(DanglingTransactionHolder::$link->transactions[0]->rolledBack);
    }

    /**
     * The MCP transports wire the same hook per message — an HTTP POST
     * to /mcp here, a stdio line below — so a tool leaving a
     * transaction open gets it rolled back exactly as an HTTP
     * controller does.
     */
    public function test_rolls_back_a_transaction_left_open_by_a_tool_over_http(): void
    {
        $app = new AppScope();
        $app->boot();

        $registry = new McpRegistry();
        $registry->register(DanglingTransactionToolController::class);
        $kernel = new Kernel($app, new Router(), mcp: new McpServer($registry, new McpDispatcher($app)));

        DanglingTransactionHolder::$link = null;

        $request = new ServerRequest('POST', '/mcp', ['Content-Type' => 'application/json']);
        $request->getBody()->write((string) \json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'begin_transaction'],
        ]));
        $request->getBody()->rewind();

        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull(DanglingTransactionHolder::$link);
        self::assertTrue(DanglingTransactionHolder::$link->transactions[0]->rolledBack);
    }

    public function test_rolls_back_a_transaction_left_open_by_a_tool_over_stdio(): void
    {
        $app = new AppScope();
        $app->boot();

        $registry = new McpRegistry();
        $registry->register(DanglingTransactionToolController::class);
        $server = new McpServer($registry, new McpDispatcher($app));

        DanglingTransactionHolder::$link = null;

        $input = \fopen('php://memory', 'r+');
        \assert($input !== false);
        \fwrite($input, (string) \json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'begin_transaction'],
        ]) . "\n");
        \rewind($input);
        $output = \fopen('php://memory', 'r+');
        \assert($output !== false);

        (new StdioTransport())->run($server, $input, $output, $app);

        self::assertNotNull(DanglingTransactionHolder::$link);
        self::assertTrue(DanglingTransactionHolder::$link->transactions[0]->rolledBack);
    }
}
