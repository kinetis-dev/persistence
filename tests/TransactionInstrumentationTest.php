<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use Kinetis\Persistence\Exception\QueryException;
use Kinetis\Persistence\Tests\Fixtures\FakeDriverTransaction;
use Kinetis\Persistence\Tests\Fixtures\FakePdoClient;
use Kinetis\Persistence\Tests\Fixtures\RecordingSqlInstrumentation;
use PHPUnit\Framework\TestCase;

/**
 * A statement run through a transaction reports the same three query
 * moments a statement run on a client does. A transaction pins its
 * connection and dispatches on it directly, so the client's own
 * instrumentation wrappers never see these statements — which is why
 * {@see \Kinetis\Persistence\Driver\AbstractTransaction} reports them
 * itself, once each, around the driver call alone.
 */
final class TransactionInstrumentationTest extends TestCase
{
    public function test_a_query_reports_dispatched_started_and_reaped_with_the_original_sql(): void
    {
        $instrumentation = new RecordingSqlInstrumentation();
        $transaction = new FakeDriverTransaction($instrumentation);

        $transaction->query('SELECT 1');

        self::assertSame(
            ['transactionStarted', 'queryDispatched', 'queryServerStarted', 'queryReaped'],
            $instrumentation->moments,
        );
        self::assertSame([['mysql', 'SELECT 1']], $instrumentation->dispatched);
        self::assertSame([['query-2', null]], $instrumentation->reaped);
    }

    /**
     * The SQL the caller wrote, not the text a native driver renders its
     * values into: a parameter value must never reach an instrumentation
     * implementation, which the contract says may export what it is
     * handed.
     */
    public function test_an_execute_reports_the_caller_sql_and_never_the_parameter_values(): void
    {
        $instrumentation = new RecordingSqlInstrumentation();
        $transaction = new FakeDriverTransaction($instrumentation);

        $transaction->execute('SELECT ? AS secret', ['hunter2']);

        self::assertSame(
            ['transactionStarted', 'queryDispatched', 'queryServerStarted', 'queryReaped'],
            $instrumentation->moments,
        );
        self::assertSame([['mysql', 'SELECT ? AS secret']], $instrumentation->dispatched);
    }

    /**
     * The pre-flight runs ahead of the span for the same reason it runs
     * ahead of the pool: a caller's own mistake is not a statement, and
     * a span opened for one would never close against a server.
     */
    public function test_an_argument_list_the_preflight_refuses_reports_no_query_moment(): void
    {
        $instrumentation = new RecordingSqlInstrumentation();
        $transaction = new FakeDriverTransaction($instrumentation);

        try {
            $transaction->execute('SELECT ?', []);
            self::fail('Expected the short argument list to be refused.');
        } catch (QueryException) {
        }

        self::assertSame(['transactionStarted'], $instrumentation->moments);
        self::assertSame(0, $transaction->dispatches);
    }

    public function test_a_failed_statement_is_reaped_with_the_throwable_the_caller_gets(): void
    {
        $instrumentation = new RecordingSqlInstrumentation();
        $transaction = new FakeDriverTransaction($instrumentation);
        $failure = new QueryException('Duplicate entry', 'INSERT INTO t VALUES (1)');
        $transaction->failNextDispatch = $failure;

        try {
            $transaction->query('INSERT INTO t VALUES (1)');
            self::fail('Expected the statement failure to propagate.');
        } catch (QueryException $e) {
            self::assertSame($failure, $e);
        }

        self::assertSame(
            ['transactionStarted', 'queryDispatched', 'queryServerStarted', 'queryReaped'],
            $instrumentation->moments,
        );
        self::assertSame([['query-2', $failure]], $instrumentation->reaped);
        // The transaction is still open: an ordinary statement failure
        // is not a terminal one, so nothing was ended.
        self::assertSame([], $instrumentation->ended);
    }

    /**
     * A failure the driver calls terminal ends the transaction on the
     * spot. The statement is still reaped first: what the server did
     * with it is settled, and handing the connection back afterwards
     * cannot change that.
     */
    public function test_a_terminal_failure_reaps_its_statement_before_the_transaction_ends(): void
    {
        $instrumentation = new RecordingSqlInstrumentation();
        $transaction = new FakeDriverTransaction($instrumentation);
        $transaction->terminal = true;
        $transaction->failNextDispatch = new QueryException('Lock wait timeout exceeded', 'UPDATE t SET n = 1');

        try {
            $transaction->query('UPDATE t SET n = 1');
            self::fail('Expected the lock failure to propagate.');
        } catch (QueryException) {
        }

        self::assertSame(
            ['transactionStarted', 'queryDispatched', 'queryServerStarted', 'queryReaped', 'transactionEnded'],
            $instrumentation->moments,
        );
        self::assertSame([['transaction-1', 'unknown']], $instrumentation->ended);
        self::assertSame([true], $transaction->released);
    }

    /**
     * BEGIN, COMMIT and ROLLBACK are the transaction's boundary, not
     * statements: they report the started/ended pair and no query moment
     * at all, on a real driver as much as on the fake one.
     */
    public function test_a_pdo_transactions_begin_and_commit_report_no_query_moment(): void
    {
        $instrumentation = new RecordingSqlInstrumentation();
        $client = FakePdoClient::overSqlite(instrumentation: $instrumentation);

        $transaction = $client->beginTransaction();
        $transaction->execute('INSERT INTO items (name) VALUES (?)', ['a']);
        $transaction->commit();

        self::assertSame([
            'transactionStarted',
            'queryDispatched',
            'queryServerStarted',
            'queryReaped',
            'transactionEnded',
        ], $instrumentation->moments);
        self::assertSame([['mysql', 'INSERT INTO items (name) VALUES (?)']], $instrumentation->dispatched);
        self::assertSame([['transaction-1', 'commit']], $instrumentation->ended);

        $client->close();
    }

    /**
     * The client's own wrappers and the transaction's must not both fire
     * for one statement: a transaction's statements go straight down its
     * pinned connection, and a statement on the root link goes through
     * the client alone.
     */
    public function test_neither_path_reports_a_statement_twice(): void
    {
        $instrumentation = new RecordingSqlInstrumentation();
        $client = FakePdoClient::overSqlite(instrumentation: $instrumentation);

        $transaction = $client->beginTransaction();
        $transaction->query('SELECT 1');
        $transaction->rollback();
        $client->query('SELECT 1');

        self::assertSame([
            'transactionStarted',
            'queryDispatched',
            'queryServerStarted',
            'queryReaped',
            'transactionEnded',
            'queryDispatched',
            'queryServerStarted',
            'queryReaped',
        ], $instrumentation->moments);
        self::assertSame([['transaction-1', 'rollback']], $instrumentation->ended);

        $client->close();
    }
}
