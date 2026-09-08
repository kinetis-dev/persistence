<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use Fiber;
use Kinetis\Instrumentation\NullTelemetry;
use Kinetis\Instrumentation\Telemetry;
use Kinetis\Instrumentation\TelemetryInterface;
use Kinetis\Persistence\Driver\MysqlLockFailure;
use Kinetis\Persistence\Exception\ConnectionException;
use Kinetis\Persistence\Exception\QueryException;
use Kinetis\Persistence\Exception\TransactionException;
use Kinetis\Persistence\Tests\Fixtures\FakeDriverTransaction;
use Kinetis\Persistence\Tests\Fixtures\FakePostgresDriverTransaction;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * AbstractTransaction's own contract: who may use a transaction, and the
 * one transition every way out goes through.
 */
final class TransactionStateTest extends TestCase
{
    /**
     * The telemetry holder is a per-process singleton, so a test that
     * swapped a recording backend in must put the default one back.
     */
    protected function tearDown(): void
    {
        Telemetry::global()->swap(new NullTelemetry());
    }

    public function test_a_foreign_fiber_cannot_run_statements(): void
    {
        $transaction = new FakeDriverTransaction();

        $queryFailure = self::inNewFiber(static fn () => $transaction->query('SELECT 1'));
        $executeFailure = self::inNewFiber(static fn () => $transaction->execute('SELECT ?', [1]));

        self::assertInstanceOf(TransactionException::class, $queryFailure);
        self::assertInstanceOf(TransactionException::class, $executeFailure);
        self::assertSame(0, $transaction->dispatches);
    }

    public function test_a_foreign_fiber_cannot_commit_or_roll_back(): void
    {
        $transaction = new FakeDriverTransaction();

        self::assertInstanceOf(TransactionException::class, self::inNewFiber(static fn () => $transaction->commit()));
        self::assertInstanceOf(TransactionException::class, self::inNewFiber(static fn () => $transaction->rollback()));
        self::assertSame([], $transaction->finished);
        self::assertTrue($transaction->isActive());
    }

    /**
     * The disposal path: close() from another Fiber ends the transaction
     * without putting a statement on a connection its owner may be
     * mid-query on, and discards that connection so the server rolls the
     * work back with the session.
     */
    public function test_a_foreign_fiber_closes_by_discarding_the_connection(): void
    {
        $transaction = new FakeDriverTransaction();

        self::assertNull(self::inNewFiber(static fn () => $transaction->close()));

        self::assertFalse($transaction->isActive());
        self::assertSame([], $transaction->finished);
        self::assertSame([true], $transaction->released);
    }

    public function test_the_owner_closes_by_rolling_back(): void
    {
        $transaction = new FakeDriverTransaction();

        $transaction->close();

        self::assertSame([false], $transaction->finished);
        self::assertSame([false], $transaction->released);
    }

    /** A Fiber that began the transaction owns it there, not in the main context. */
    public function test_a_transaction_begun_in_a_fiber_is_owned_by_it(): void
    {
        $fiber = new Fiber(static function (): FakeDriverTransaction {
            $transaction = new FakeDriverTransaction();
            Fiber::suspend();

            return $transaction;
        });

        $fiber->start();
        $fiber->resume();
        /** @var FakeDriverTransaction $transaction */
        $transaction = $fiber->getReturn();

        $this->expectException(TransactionException::class);
        $transaction->query('SELECT 1');
    }

    public function test_a_transaction_releases_exactly_once(): void
    {
        $transaction = new FakeDriverTransaction();

        $transaction->commit();
        $transaction->rollback();
        $transaction->close();

        self::assertSame([true], $transaction->finished);
        self::assertSame([false], $transaction->released);
    }

    /**
     * A COMMIT the server refused leaves the transaction's outcome and
     * the connection's state unknown, so the transaction ends, the
     * connection is discarded rather than handed to the next caller, and
     * the span says unknown — neither the commit that was asked for nor
     * a rollback anything confirmed.
     */
    public function test_a_failing_finish_ends_and_discards_the_connection(): void
    {
        $telemetry = $this->createMock(TelemetryInterface::class);
        $telemetry->method('transactionStarted')->willReturn('token');
        $telemetry->expects(self::once())->method('transactionEnded')->with('token', 'unknown');
        Telemetry::global()->swap($telemetry);

        $transaction = new FakeDriverTransaction();
        $transaction->failFinish = new TransactionException('Commit failed');

        try {
            $transaction->commit();
            self::fail('Expected the commit failure to propagate.');
        } catch (TransactionException) {
        }

        self::assertFalse($transaction->isActive());
        self::assertSame([true], $transaction->finished);
        self::assertSame([true], $transaction->released);
    }

    /**
     * With the finish itself fine, the release failure is the whole
     * story — and the span still records the commit the server
     * acknowledged: handing the connection back is not part of it.
     */
    public function test_a_failing_release_is_reported(): void
    {
        $telemetry = $this->createMock(TelemetryInterface::class);
        $telemetry->method('transactionStarted')->willReturn('token');
        $telemetry->expects(self::once())->method('transactionEnded')->with('token', 'commit');
        Telemetry::global()->swap($telemetry);

        $transaction = new FakeDriverTransaction();
        $transaction->failRelease = new ConnectionException('The pool refused the connection');

        try {
            $transaction->commit();
            self::fail('Expected the release failure to propagate.');
        } catch (ConnectionException) {
        }

        self::assertSame([true], $transaction->finished);
        self::assertFalse($transaction->isActive());
    }

    /** A release failing on top of a failed finish is secondary to it. */
    public function test_a_finish_failure_outranks_a_release_failure(): void
    {
        $transaction = new FakeDriverTransaction();
        $transaction->failFinish = new TransactionException('Commit failed');
        $transaction->failRelease = new ConnectionException('The pool refused the connection');

        $this->expectException(TransactionException::class);
        $this->expectExceptionMessage('Commit failed');
        $transaction->commit();
    }

    /**
     * The disposal hook can close a transaction whose owner is suspended
     * on the wire; that owner then resumes with the connection failure
     * and reaches the terminal transition a second time. It settles
     * nothing: one release, one span, and an outcome nothing confirmed.
     */
    public function test_a_foreign_close_during_a_statement_ends_the_transaction_once(): void
    {
        $telemetry = $this->createMock(TelemetryInterface::class);
        $telemetry->method('transactionStarted')->willReturn('token');
        $telemetry->expects(self::once())->method('transactionEnded')->with('token', 'unknown');
        Telemetry::global()->swap($telemetry);

        $transaction = new FakeDriverTransaction();
        $transaction->duringDispatch = static function () use ($transaction): void {
            self::inNewFiber(static fn () => $transaction->close());
        };
        $transaction->failNextDispatch = new ConnectionException('MySQL connection lost');

        try {
            $transaction->query('SELECT 1');
            self::fail('Expected the connection failure to propagate.');
        } catch (ConnectionException) {
        }

        self::assertSame([], $transaction->finished);
        self::assertSame([true], $transaction->released);
    }

    /**
     * A transaction the server ended underneath the object settles the
     * first time anything asks: no statement goes on the wire, the
     * connection is handed back once, and the object refuses work
     * afterwards — so a caller that trusts the answer and never closes
     * it leaves nothing stranded.
     */
    public function test_inspecting_a_server_ended_transaction_settles_it(): void
    {
        $transaction = new FakeDriverTransaction();
        $transaction->onConnection = false;

        self::assertFalse($transaction->isActive());
        self::assertTrue($transaction->isClosed());
        self::assertSame([], $transaction->finished);
        self::assertSame([false], $transaction->released);

        // Asking again settles nothing further.
        self::assertFalse($transaction->isActive());
        self::assertSame([false], $transaction->released);

        $this->expectException(TransactionException::class);
        $transaction->commit();
    }

    /**
     * A statement is where that same settling has to happen too: the
     * transaction ends and the statement is refused, rather than going
     * down a connection that would run it in autocommit.
     */
    public function test_a_statement_on_a_server_ended_transaction_is_refused(): void
    {
        $transaction = new FakeDriverTransaction();
        $transaction->onConnection = false;

        try {
            $transaction->query('SELECT 1');
            self::fail('Expected the statement to be refused.');
        } catch (TransactionException) {
        }

        self::assertSame(0, $transaction->dispatches);
        self::assertSame([], $transaction->finished);
        self::assertSame([false], $transaction->released);
    }

    /**
     * Rolling back a transaction the server has already ended settles it
     * without sending a ROLLBACK that would run in autocommit. The
     * connection itself is healthy, so it is handed back rather than
     * discarded, and asking again does nothing further.
     */
    public function test_rollback_after_the_transaction_ended_is_a_no_op(): void
    {
        $transaction = new FakeDriverTransaction();
        $transaction->onConnection = false;

        $transaction->rollback();

        self::assertSame([], $transaction->finished);
        self::assertSame([false], $transaction->released);

        $transaction->rollback();

        self::assertSame([], $transaction->finished);
        self::assertSame([false], $transaction->released);
    }

    public function test_commit_after_the_transaction_ended_throws(): void
    {
        $transaction = new FakeDriverTransaction();
        $transaction->rollback();

        $this->expectException(TransactionException::class);
        $transaction->commit();
    }

    /**
     * Losing the connection ends the transaction wherever the server is:
     * nothing can be sent on it, so it is abandoned and the connection
     * discarded rather than rolled back.
     */
    public function test_a_lost_connection_abandons_the_transaction(): void
    {
        $transaction = new FakeDriverTransaction();
        $transaction->failNextDispatch = new ConnectionException('MySQL connection lost');

        try {
            $transaction->query('SELECT 1');
            self::fail('Expected the connection failure to propagate.');
        } catch (ConnectionException) {
        }

        self::assertFalse($transaction->isActive());
        self::assertSame([], $transaction->finished);
        self::assertSame([true], $transaction->released);

        // And nothing later sends a statement to a session that is gone.
        $transaction->rollback();
        self::assertSame([], $transaction->finished);
    }

    /**
     * A server that ends the transaction with the statement it just
     * answered — a deadlock it resolved by rolling the whole thing back
     * — settles the transaction there, so the write a caller tries next
     * is refused instead of running in autocommit.
     */
    public function test_a_statement_failure_that_ended_the_transaction_settles_it(): void
    {
        $telemetry = $this->createMock(TelemetryInterface::class);
        $telemetry->method('transactionStarted')->willReturn('token');
        $telemetry->expects(self::once())->method('transactionEnded')->with('token', 'unknown');
        Telemetry::global()->swap($telemetry);

        $transaction = new FakeDriverTransaction();
        $transaction->failNextDispatch = new QueryException('Deadlock found when trying to get lock');
        $transaction->duringDispatch = static function () use ($transaction): void {
            $transaction->onConnection = false;
        };

        try {
            $transaction->execute('UPDATE t SET v = ? WHERE id = ?', [1, 2]);
            self::fail('Expected the statement failure to propagate.');
        } catch (QueryException) {
        }

        // Settled by the failure itself, before anything asks. Nothing
        // was sent: the server has already rolled it back.
        self::assertSame([], $transaction->finished);
        self::assertSame([false], $transaction->released);
        self::assertFalse($transaction->isActive());

        try {
            $transaction->execute('UPDATE t SET v = ? WHERE id = ?', [1, 3]);
            self::fail('Expected the next write to be refused.');
        } catch (TransactionException $e) {
            self::assertStringContainsString('no longer open', $e->getMessage());
        }

        self::assertSame(1, $transaction->dispatches);
    }

    /**
     * The same for a statement that succeeded and ended the transaction
     * anyway — MySQL's implicit commit on DDL, where a driver can see
     * it.
     */
    public function test_a_successful_statement_that_ended_the_transaction_settles_it(): void
    {
        $transaction = new FakeDriverTransaction();
        $transaction->duringDispatch = static function () use ($transaction): void {
            $transaction->onConnection = false;
        };

        $transaction->query('CREATE TABLE t (id INT)');

        // Settled by the statement's own answer, before anything asks.
        self::assertSame([], $transaction->finished);
        self::assertSame([false], $transaction->released);
        self::assertFalse($transaction->isActive());

        $this->expectException(TransactionException::class);
        $transaction->query('SELECT 1');
    }

    /** A failed statement leaves a MySQL transaction usable. */
    public function test_a_query_failure_does_not_end_a_mysql_transaction(): void
    {
        $transaction = new FakeDriverTransaction();
        $transaction->failNextDispatch = new QueryException('Unknown column');

        try {
            $transaction->query('SELECT missing');
            self::fail('Expected the query failure to propagate.');
        } catch (QueryException) {
        }

        self::assertTrue($transaction->isActive());
        $transaction->commit();
        self::assertSame([true], $transaction->finished);
    }

    /**
     * Postgres aborts the whole transaction on any statement error and
     * answers a later COMMIT with a ROLLBACK tag and no error, so the
     * commit is refused and rolled back rather than reported as one.
     */
    public function test_a_query_failure_makes_a_postgres_commit_roll_back(): void
    {
        $transaction = new FakePostgresDriverTransaction();
        $transaction->failNextDispatch = new QueryException('relation does not exist');

        try {
            $transaction->query('SELECT * FROM missing');
            self::fail('Expected the query failure to propagate.');
        } catch (QueryException) {
        }

        try {
            $transaction->commit();
            self::fail('Expected the commit to be refused.');
        } catch (TransactionException) {
        }

        self::assertSame([false], $transaction->finished);
        self::assertFalse($transaction->isActive());
    }

    /** A refused argument list is the caller's mistake, not the server's answer. */
    public function test_a_rejected_argument_list_leaves_a_postgres_transaction_usable(): void
    {
        $transaction = new FakePostgresDriverTransaction();

        try {
            $transaction->execute('SELECT ?, ?', [1]);
            self::fail('Expected the argument list to be refused.');
        } catch (QueryException) {
        }

        self::assertSame(0, $transaction->dispatches);
        $transaction->commit();
        self::assertSame([true], $transaction->finished);
    }

    /**
     * The interruption a request boundary produces: the owner is
     * suspended on a COMMIT it will never hear back about, and the
     * request ends. isActive() still reports the transaction live —
     * settling is what closing it means — and close() takes the
     * connection out of service. The owner comes back to a
     * ConnectionException, and between the two of them the connection
     * is released once and the span closed once, as unknown: the server
     * may well have committed.
     */
    public function test_a_foreign_close_settles_a_pending_commit_exactly_once(): void
    {
        $telemetry = $this->createMock(TelemetryInterface::class);
        $telemetry->method('transactionStarted')->willReturn('token');
        $telemetry->expects(self::once())->method('transactionEnded')->with('token', 'unknown');
        Telemetry::global()->swap($telemetry);

        $transaction = new FakeDriverTransaction();
        $stillActive = null;
        $settledByClose = null;
        $transaction->duringFinish = static function () use ($transaction, &$stillActive, &$settledByClose): void {
            $stillActive = $transaction->isActive();
            self::inNewFiber(static fn () => $transaction->close());
            $settledByClose = !$transaction->isActive();
        };
        // What the driver throws into the owner once its connection has
        // been taken out from under the COMMIT. The owner gets that
        // failure itself: what the driver saw outranks the interruption
        // the transaction reports when the driver saw nothing.
        $lostConnection = new ConnectionException('The MySQL connection was closed with a statement in flight');
        $transaction->failFinish = $lostConnection;

        try {
            $transaction->commit();
            self::fail('Expected the interrupted commit to propagate.');
        } catch (ConnectionException $e) {
            self::assertSame($lostConnection, $e);
        }

        self::assertTrue($stillActive, 'A transaction finishing on the wire is still the disposal hook\'s to close.');
        self::assertTrue($settledByClose, 'close() is what settles a transaction whose owner is parked on a finish.');
        self::assertSame([true], $transaction->finished);
        self::assertSame([true], $transaction->released);
        self::assertFalse($transaction->isActive());
    }

    /**
     * The same interruption where the connection dies without the
     * driver reporting it to the owner at all: close() has settled the
     * transaction, so the owner's own terminal transition finds nothing
     * left to do and says why.
     */
    public function test_an_interrupted_finish_that_answers_normally_still_reports_the_interruption(): void
    {
        $transaction = new FakeDriverTransaction();
        $transaction->duringFinish = static function () use ($transaction): void {
            self::inNewFiber(static fn () => $transaction->close());
        };

        try {
            $transaction->commit();
            self::fail('Expected the interrupted commit to propagate.');
        } catch (ConnectionException $e) {
            self::assertStringContainsString('unknown', $e->getMessage());
        }

        self::assertSame([true], $transaction->released);
    }

    /**
     * A failure the driver calls terminal for its connection — MySQL's
     * lock-wait timeout and deadlock ({@see MysqlLockFailure}) — ends
     * the transaction, discards the connection rather than pooling one
     * the server may have rolled back under, and records the outcome as
     * the unknown it is.
     */
    public function test_a_terminal_failure_ends_and_discards_the_connection(): void
    {
        $telemetry = $this->createMock(TelemetryInterface::class);
        $telemetry->method('transactionStarted')->willReturn('token');
        $telemetry->expects(self::once())->method('transactionEnded')->with('token', 'unknown');
        Telemetry::global()->swap($telemetry);

        $transaction = new FakeDriverTransaction();
        $transaction->terminal = true;
        $transaction->failNextDispatch = new QueryException('Lock wait timeout exceeded', 'UPDATE t SET v = 1', null, 1205);

        try {
            $transaction->query('UPDATE t SET v = 1');
            self::fail('Expected the statement failure to propagate.');
        } catch (QueryException $e) {
            self::assertSame(1205, $e->getCode());
        }

        self::assertFalse($transaction->isActive());
        self::assertSame([], $transaction->finished);
        self::assertSame([true], $transaction->released);
    }

    /** Only the two lock failures are terminal; every other server error leaves the transaction alone. */
    public function test_which_mysql_errors_end_a_transaction(): void
    {
        self::assertTrue(MysqlLockFailure::isTerminal(new QueryException('Lock wait timeout', '', null, 1205)));
        self::assertTrue(MysqlLockFailure::isTerminal(new QueryException('Deadlock found', '', null, 1213)));
        self::assertFalse(MysqlLockFailure::isTerminal(new QueryException('Unknown column', '', null, 1054)));
        self::assertFalse(MysqlLockFailure::isTerminal(new QueryException('Query failed')));
    }

    public function test_nesting_is_refused(): void
    {
        $this->expectException(TransactionException::class);
        new FakeDriverTransaction()->beginTransaction();
    }

    /** Runs $callback in its own Fiber and returns whatever it threw, or null. */
    private static function inNewFiber(callable $callback): ?Throwable
    {
        $caught = null;

        $fiber = new Fiber(static function () use ($callback, &$caught): void {
            try {
                $callback();
            } catch (Throwable $e) {
                $caught = $e;
            }
        });
        $fiber->start();

        return $caught;
    }
}
