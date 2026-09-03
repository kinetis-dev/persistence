<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use Kinetis\Container\AppScope;
use Kinetis\Persistence\Tests\Fixtures\DanglingTransactionHolder;
use Kinetis\Persistence\Tests\Fixtures\DanglingTransactionJob;
use Kinetis\Persistence\Tests\Fixtures\InMemoryLogger;
use Kinetis\Persistence\Tests\Fixtures\NoOpJob;
use Kinetis\Persistence\Tests\Fixtures\SingleJobQueue;
use Kinetis\Persistence\Tests\Fixtures\ThrowingDanglingTransactionJob;
use Kinetis\Queue\QueueWorker;
use Kinetis\Queue\SyncQueue;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The "kinetis/persistence is actually installed" half of QueueWorker's
 * and SyncQueue's TransactionGuard wiring — the counterpart to
 * kinetis/queue's own QueueWorkerTest::test_processes_a_job_normally_when_the_persistence_package_is_not_installed()
 * and SyncQueueTest::test_pushes_a_job_normally_when_the_persistence_package_is_not_installed().
 * Only this package has both QueueWorker/SyncQueue and TransactionGuard
 * simultaneously available (it depends on kinetis/framework and, for
 * tests only, kinetis/queue; queue never depends the other way), so this
 * is the one place the real dispose-hook wiring can be proven end to
 * end — real rollback, not merely that a callback was registered.
 */
final class QueueIntegrationTest extends TestCase
{
    /**
     * @param (callable(AppScope): void)|null $beforeBoot registrations must
     *        happen before boot() locks the container
     */
    private function app(?callable $beforeBoot = null): AppScope
    {
        $app = new AppScope();

        if ($beforeBoot !== null) {
            $beforeBoot($app);
        }

        $app->boot();

        return $app;
    }

    public function test_a_queue_worker_rolls_back_a_transaction_left_open_by_a_job_that_completes_successfully(): void
    {
        self::assertTrue(class_exists('Kinetis\Persistence\TransactionGuard'));

        $queue = new SingleJobQueue();
        $queue->push(new DanglingTransactionJob());

        DanglingTransactionHolder::$link = null;

        $worker = new QueueWorker($this->app(), $queue);
        self::assertTrue($worker->processNext());

        self::assertNotNull(DanglingTransactionHolder::$link);
        self::assertTrue(DanglingTransactionHolder::$link->transactions[0]->rolledBack);
        self::assertSame([1], $queue->acked, 'the job itself succeeded — the dispose hook rolling back the leftover transaction does not turn that into a failure');
    }

    public function test_a_queue_worker_rolls_back_a_transaction_left_open_by_a_job_that_throws(): void
    {
        $queue = new SingleJobQueue();
        $queue->push(new ThrowingDanglingTransactionJob());

        DanglingTransactionHolder::$link = null;

        $worker = new QueueWorker($this->app(), $queue);
        self::assertTrue($worker->processNext());

        self::assertNotNull(DanglingTransactionHolder::$link);
        self::assertTrue(DanglingTransactionHolder::$link->transactions[0]->rolledBack);
        self::assertSame([], $queue->acked);
    }

    /**
     * A job that never resolves TransactionGuard at all — proving the
     * dispose hook registered against its scope is a genuine no-op: no
     * rollback-warning log line, since rollbackDangling() only logs when
     * it actually finds something to close.
     */
    public function test_a_queue_worker_job_that_never_resolves_transaction_guard_remains_a_no_op(): void
    {
        $logger = new InMemoryLogger();
        $app = $this->app(static fn (AppScope $app) => $app->instance(LoggerInterface::class, $logger));

        $queue = new SingleJobQueue();
        $queue->push(new NoOpJob());

        DanglingTransactionHolder::$link = null;

        $worker = new QueueWorker($app, $queue);
        self::assertTrue($worker->processNext());

        self::assertSame([1], $queue->acked);
        self::assertNull(DanglingTransactionHolder::$link);
        self::assertSame([], $logger->records, 'no transaction was ever opened, so there is nothing for the dispose hook to roll back or warn about');
    }

    public function test_sync_queue_rolls_back_a_transaction_left_open_by_a_job_that_completes_successfully(): void
    {
        DanglingTransactionHolder::$link = null;

        (new SyncQueue($this->app()))->push(new DanglingTransactionJob());

        self::assertNotNull(DanglingTransactionHolder::$link);
        self::assertTrue(DanglingTransactionHolder::$link->transactions[0]->rolledBack);
    }

    /**
     * Unlike QueueWorker, SyncQueue lets a job's exception propagate to
     * the caller rather than swallowing it — but the scope it created
     * still disposes in its own finally block first, so the rollback
     * still happens before that exception reaches this test.
     */
    public function test_sync_queue_rolls_back_a_transaction_left_open_by_a_job_that_throws(): void
    {
        DanglingTransactionHolder::$link = null;

        try {
            (new SyncQueue($this->app()))->push(new ThrowingDanglingTransactionJob());
            self::fail('Expected the job\'s exception to propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('deliberate failure', $e->getMessage());
        }

        self::assertNotNull(DanglingTransactionHolder::$link);
        self::assertTrue(DanglingTransactionHolder::$link->transactions[0]->rolledBack);
    }
}
