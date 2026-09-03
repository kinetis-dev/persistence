<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use Kinetis\Container\AppScope;
use Kinetis\Http\Kernel;
use Kinetis\Http\Routing\Router;
use Kinetis\Config\Config;
use Kinetis\Mcp\Http\McpController;
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
        $app->instance(Config::class, new Config([]));
        $registry = new McpRegistry();
        $registry->register(DanglingTransactionToolController::class);
        $app->instance(McpServer::class, new McpServer($registry, new McpDispatcher($app)));
        $app->boot();

        $router = new Router();
        $router->register(McpController::class);
        $kernel = new Kernel($app, $router, middlewareGroups: ['mcp' => []]);

        DanglingTransactionHolder::$link = null;

        $request = new ServerRequest(
            'POST',
            '/mcp',
            [
                'Content-Type' => 'application/json',
                'MCP-Protocol-Version' => '2026-07-28',
                'Mcp-Method' => 'tools/call',
                'Mcp-Name' => 'begin_transaction',
            ],
        );
        $request->getBody()->write((string) \json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'begin_transaction',
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                    'io.modelcontextprotocol/clientCapabilities' => (object) [],
                ],
            ],
        ]));
        $request->getBody()->rewind();

        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());

        // A 200 alone doesn't prove the tool actually ran — most, but
        // not all, preflight/validation rejections map to 400, so the
        // envelope itself has to be checked too: a genuine result, never
        // an error, and the tool's own real return value inside it, not
        // just "some result key is present."
        $body = \json_decode((string) $response->getBody(), true);
        self::assertArrayNotHasKey('error', $body);
        self::assertFalse($body['result']['isError']);
        self::assertSame(
            ['started' => true],
            \json_decode($body['result']['content'][0]['text'], true),
        );

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
            'params' => [
                'name' => 'begin_transaction',
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                    'io.modelcontextprotocol/clientCapabilities' => (object) [],
                ],
            ],
        ]) . "\n");
        \rewind($input);
        $output = \fopen('php://memory', 'r+');
        \assert($output !== false);

        (new StdioTransport())->run($server, $input, $output, $app);

        // Decode and assert the actual output line rather than ignoring
        // it — a pre-dispatch rejection would still leave $output
        // non-empty (a JSON-RPC error line), so only inspecting it can
        // tell that apart from a genuine successful dispatch.
        \rewind($output);
        $written = (string) \stream_get_contents($output);
        $lines = \array_values(\array_filter(\explode("\n", $written)));
        self::assertCount(1, $lines);

        $response = \json_decode($lines[0], true);
        self::assertArrayNotHasKey('error', $response);
        self::assertFalse($response['result']['isError']);
        self::assertSame(
            ['started' => true],
            \json_decode($response['result']['content'][0]['text'], true),
        );

        self::assertNotNull(DanglingTransactionHolder::$link);
        self::assertTrue(DanglingTransactionHolder::$link->transactions[0]->rolledBack);
    }
}
