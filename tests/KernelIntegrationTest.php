<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use Kinetis\Container\AppScope;
use Kinetis\Http\Kernel;
use Kinetis\Http\Routing\Router;
use Kinetis\Persistence\Tests\Fixtures\DanglingTransactionController;
use Kinetis\Persistence\Tests\Fixtures\DanglingTransactionHolder;
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
}
