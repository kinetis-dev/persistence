<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use Kinetis\Instrumentation\NullTelemetry;
use Kinetis\Instrumentation\Telemetry;
use Kinetis\Instrumentation\TelemetryInterface;
use Kinetis\Persistence\Exception\TransactionException;
use Kinetis\Persistence\Tests\Fixtures\FakePooledClient;
use Kinetis\Persistence\Tests\Fixtures\InMemoryLogger;
use Kinetis\Persistence\TransactionGuard;
use PHPUnit\Framework\TestCase;

/**
 * What happens to a transaction begun straight off a link that nothing
 * ever commits, rolls back or closes — an exception path in application
 * code that drops it, with no `TransactionGuard` tracking it.
 *
 * The last reference going away is the only event left, so that is where
 * the connection is given up: discarded, not rolled back on the wire,
 * because nothing running in a destructor can wait for a server's
 * answer. Proven against {@see FakePooledClient}, which carries the
 * ownership both native drivers do — the calling Fiber's entry and the
 * pinned connection — without needing a server to hold either.
 */
final class AbandonedTransactionTest extends TestCase
{
    /** The telemetry holder is a per-process singleton. */
    protected function tearDown(): void
    {
        Telemetry::global()->swap(new NullTelemetry());
    }

    /**
     * The whole point of the ownership: while the transaction is open,
     * the Fiber holding it is refused at the root link, since a
     * statement there would land on a different pooled connection in
     * autocommit.
     */
    public function test_the_root_link_is_refused_while_the_transaction_is_open(): void
    {
        $client = new FakePooledClient();
        $transaction = $client->beginTransaction();

        try {
            $client->query('SELECT 1');
            self::fail('Expected the root link to be refused.');
        } catch (TransactionException $e) {
            self::assertStringContainsString('open transaction on this client', $e->getMessage());
        }

        $transaction->rollback();
    }

    /**
     * Dropping the only reference is what ends it: the owner entry goes,
     * so the Fiber's next root-link statement is served, and the
     * connection goes with it rather than staying pinned for the
     * client's life.
     */
    public function test_dropping_the_last_reference_gives_the_fiber_its_root_link_back(): void
    {
        $client = new FakePooledClient();
        $transaction = $client->beginTransaction();

        unset($transaction);

        self::assertSame([true], $client->released);
        self::assertSame([], $client->pinned);
        $client->query('SELECT 1');
        self::assertSame(['SELECT 1'], $client->served);
    }

    /**
     * Discarded, never pooled. The server is holding a transaction on
     * that connection and nothing sent a ROLLBACK, so handing it to the
     * next caller would put their statements inside abandoned work; the
     * connection is taken out of service instead, and the server rolls
     * the work back with the session.
     */
    public function test_an_abandoned_transaction_discards_its_connection_without_sending_anything(): void
    {
        $telemetry = $this->createMock(TelemetryInterface::class);
        $telemetry->method('transactionStarted')->willReturn('token');
        $telemetry->expects(self::once())->method('transactionEnded')->with('token', 'unknown');
        Telemetry::global()->swap($telemetry);

        $client = new FakePooledClient();
        $client->beginTransaction()->execute('INSERT INTO items (name) VALUES (?)', ['a']);

        self::assertSame(['INSERT INTO items (name) VALUES (?)'], $client->onConnection);
        self::assertSame([], $client->finished);
        self::assertSame([true], $client->released);
        self::assertSame([], $client->idle);
    }

    /** A transaction that ended already settled there; being destroyed adds nothing. */
    public function test_destroying_a_committed_transaction_releases_nothing_further(): void
    {
        $client = new FakePooledClient();
        $transaction = $client->beginTransaction();
        $transaction->commit();

        unset($transaction);

        self::assertSame([true], $client->finished);
        self::assertSame([false], $client->released);
        self::assertCount(1, $client->idle);
    }

    /**
     * The route that costs neither the connection nor the certainty:
     * a transaction the guard tracks is held by it, so dropping the
     * caller's own reference abandons nothing, and disposal rolls it
     * back on the wire and hands the connection back to the pool.
     */
    public function test_a_guard_transaction_is_not_abandoned_when_the_caller_drops_it(): void
    {
        $client = new FakePooledClient();
        $guard = new TransactionGuard(new InMemoryLogger());
        $transaction = $guard->beginTransaction($client);

        unset($transaction);

        self::assertSame([], $client->released);
        self::assertCount(1, $client->pinned);

        $guard->rollbackDangling();

        self::assertSame([false], $client->finished);
        self::assertSame([false], $client->released);
        self::assertCount(1, $client->idle);
    }
}
