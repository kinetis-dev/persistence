<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use Fiber;
use Kinetis\Persistence\Exception\ConnectionException;
use Kinetis\Persistence\Exception\QueryException;
use Kinetis\Persistence\Exception\TransactionException;
use Kinetis\Persistence\Tests\Fixtures\FakeDriverTransaction;
use Kinetis\Persistence\Tests\Fixtures\FakePdoClient;
use Kinetis\Persistence\Tests\Fixtures\FakePooledClient;
use Kinetis\Persistence\Tests\Fixtures\InMemoryLogger;
use Kinetis\Persistence\Tests\Fixtures\ThrowingSqlInstrumentation;
use Kinetis\Persistence\TransactionGuard;
use PHPUnit\Framework\TestCase;

/**
 * An instrumentation that throws at every moment reports nothing, and
 * changes nothing: every result, failure, outcome and release is the one
 * the same work produces with no instrumentation at all.
 */
final class SqlInstrumentationContainmentTest extends TestCase
{
    private string $errorLog;

    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        $this->errorLog = \tempnam(\sys_get_temp_dir(), 'kinetis-instrumentation-');
        $this->previousErrorLog = \ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        \ini_set('error_log', (string) $this->previousErrorLog);
        \unlink($this->errorLog);
    }

    public function test_a_successful_query_returns_its_result(): void
    {
        $instrumentation = new ThrowingSqlInstrumentation();
        $client = FakePdoClient::overSqlite(instrumentation: $instrumentation);

        $client->execute('INSERT INTO items (name) VALUES (?)', ['a']);

        self::assertSame(1, (int) $client->query('SELECT COUNT(*) AS n FROM items')->fetchRow()['n']);
        self::assertSame(
            ['queryDispatched', 'queryServerStarted', 'queryReaped', 'queryDispatched', 'queryServerStarted', 'queryReaped'],
            $instrumentation->moments,
        );
    }

    public function test_a_failing_query_throws_its_own_failure(): void
    {
        $client = FakePdoClient::overSqlite(instrumentation: new ThrowingSqlInstrumentation());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('no such table');

        $client->query('SELECT * FROM missing');
    }

    public function test_a_commit_is_acknowledged_and_its_connection_handed_back(): void
    {
        $instrumentation = new ThrowingSqlInstrumentation();
        $transaction = new FakeDriverTransaction($instrumentation);

        $transaction->commit();

        self::assertFalse($transaction->isActive());
        self::assertSame([true], $transaction->finished);
        self::assertSame([false], $transaction->released);
        self::assertSame(['transactionStarted', 'transactionEnded'], $instrumentation->moments);
    }

    public function test_a_rollback_is_acknowledged_and_its_connection_handed_back(): void
    {
        $transaction = new FakeDriverTransaction(new ThrowingSqlInstrumentation());

        $transaction->rollback();

        self::assertFalse($transaction->isActive());
        self::assertSame([false], $transaction->finished);
        self::assertSame([false], $transaction->released);
    }

    public function test_a_failed_commit_throws_its_own_failure_and_discards_the_connection(): void
    {
        $transaction = new FakeDriverTransaction(new ThrowingSqlInstrumentation());
        $transaction->failFinish = new TransactionException('Commit failed');

        try {
            $transaction->commit();
            self::fail('Expected the commit failure to propagate.');
        } catch (TransactionException $e) {
            self::assertSame('Commit failed', $e->getMessage());
        }

        self::assertSame([true], $transaction->released);
    }

    public function test_a_pdo_transaction_commits_its_work(): void
    {
        $client = FakePdoClient::overSqlite(instrumentation: new ThrowingSqlInstrumentation());
        $transaction = $client->beginTransaction();
        $transaction->execute('INSERT INTO items (name) VALUES (?)', ['a']);

        $transaction->commit();

        self::assertSame(1, (int) $client->query('SELECT COUNT(*) AS n FROM items')->fetchRow()['n']);
    }

    public function test_cleanup_closes_a_dangling_transaction_and_releases_its_connection(): void
    {
        $client = new FakePooledClient(new ThrowingSqlInstrumentation());
        $guard = new TransactionGuard(new InMemoryLogger());
        $guard->beginTransaction($client);

        $guard->rollbackDangling();

        self::assertSame([false], $client->finished);
        self::assertSame([false], $client->released);
        self::assertSame([], $client->pinned);
    }

    public function test_a_foreign_close_discards_the_connection(): void
    {
        $transaction = new FakeDriverTransaction(new ThrowingSqlInstrumentation());

        new Fiber(static fn () => $transaction->close())->start();

        self::assertFalse($transaction->isActive());
        self::assertSame([], $transaction->finished);
        self::assertSame([true], $transaction->released);
    }

    public function test_an_abandoned_transaction_discards_its_connection(): void
    {
        $client = new FakePooledClient(new ThrowingSqlInstrumentation());
        $client->beginTransaction();

        self::assertSame([true], $client->released);
        self::assertSame([], $client->pinned);
    }

    public function test_a_lost_connection_still_reaches_the_caller(): void
    {
        $transaction = new FakeDriverTransaction(new ThrowingSqlInstrumentation());
        $transaction->failNextDispatch = new ConnectionException('MySQL connection lost');

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('MySQL connection lost');

        $transaction->query('SELECT 1');
    }

    /**
     * A contained failure is reported once per moment, naming the moment
     * and both classes — never the exception's message, which can carry
     * SQL text.
     */
    public function test_a_contained_failure_is_reported_without_its_message(): void
    {
        $transaction = new FakeDriverTransaction(new ThrowingSqlInstrumentation());
        $transaction->commit();

        $log = (string) \file_get_contents($this->errorLog);

        self::assertStringContainsString(
            'Kinetis SQL instrumentation moment "transactionStarted" failed on ' . ThrowingSqlInstrumentation::class . ' (RuntimeException)',
            $log,
        );
        self::assertStringContainsString('moment "transactionEnded"', $log);
        self::assertStringNotContainsString(ThrowingSqlInstrumentation::MESSAGE, $log);
    }
}
