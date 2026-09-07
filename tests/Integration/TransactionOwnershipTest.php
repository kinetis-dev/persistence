<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Integration;

use Fiber;
use Kinetis\Instrumentation\NullTelemetry;
use Kinetis\Instrumentation\Telemetry;
use Kinetis\Instrumentation\TelemetryInterface;
use Kinetis\Persistence\ConnectionOptions;
use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Contract\SqlTransaction;
use Kinetis\Persistence\Exception\ConnectionException;
use Kinetis\Persistence\Exception\QueryException;
use Kinetis\Persistence\Exception\TransactionException;
use PHPUnit\Framework\Attributes\DataProvider;
use Revolt\EventLoop;
use Throwable;

/**
 * Who may use a transaction, and what a server does to one, against the
 * real backends.
 */
final class TransactionOwnershipTest extends DriverCase
{
    /**
     * The telemetry holder is a per-process singleton, so a test that
     * swapped a recording backend in must put the default one back.
     */
    protected function tearDown(): void
    {
        Telemetry::global()->swap(new NullTelemetry());
    }

    /** @return iterable<string, array{string}> */
    public static function mysqlDrivers(): iterable
    {
        yield 'mysqli-async' => ['mysqli-async'];
        yield 'pdo-mysql' => ['pdo-mysql'];
    }

    /** @return iterable<string, array{string}> */
    public static function nativeDrivers(): iterable
    {
        yield 'mysqli-async' => ['mysqli-async'];
        yield 'pgsql-async' => ['pgsql-async'];
    }

    /** @return iterable<string, array{string}> */
    public static function pdoDrivers(): iterable
    {
        yield 'pdo-mysql' => ['pdo-mysql'];
        yield 'pdo-pgsql' => ['pdo-pgsql'];
    }

    /** @return iterable<string, array{string}> */
    public static function postgresDrivers(): iterable
    {
        yield 'pgsql-async' => ['pgsql-async'];
        yield 'pdo-pgsql' => ['pdo-pgsql'];
    }

    /**
     * A pooled client serves the root link on a different connection, in
     * autocommit — so a Fiber holding a transaction is refused there, and
     * the write it thought it was making cannot survive its own rollback.
     */
    #[DataProvider('nativeDrivers')]
    public function test_the_root_link_is_refused_for_the_fiber_holding_a_transaction(string $driver): void
    {
        $db = self::makeClient($driver);
        self::createTable($db, $driver, 'tx_own');

        $transaction = $db->beginTransaction();

        try {
            $db->execute('INSERT INTO tx_own (s) VALUES (?)', ['root']);
            self::fail('Expected the root link to be refused.');
        } catch (TransactionException $e) {
            self::assertStringContainsString('open transaction on this client', $e->getMessage());
        }

        $transaction->rollback();
        self::assertSame(0, self::rowCount($db));
        $db->close();
    }

    /**
     * The rule is per Fiber, not per client: the pool exists so other
     * Fibers keep working, including with transactions of their own.
     */
    #[DataProvider('nativeDrivers')]
    public function test_another_fiber_still_gets_its_own_connection_and_its_own_transaction(string $driver): void
    {
        $db = self::makeClient($driver);
        self::createTable($db, $driver, 'tx_own');

        $outer = $db->beginTransaction();
        $outer->execute('INSERT INTO tx_own (s) VALUES (?)', ['outer']);

        self::concurrently([
            static function () use ($db): void {
                $db->execute('INSERT INTO tx_own (s) VALUES (?)', ['root-elsewhere']);

                $inner = $db->beginTransaction();
                $inner->execute('INSERT INTO tx_own (s) VALUES (?)', ['inner']);
                $inner->commit();
            },
        ]);

        $outer->rollback();

        // The other Fiber's two committed rows survive; the rolled-back
        // transaction's own does not.
        self::assertSame(2, self::rowCount($db));
        $db->query('DROP TABLE tx_own');
        $db->close();
    }

    /**
     * Postgres aborts the whole transaction on any statement error and
     * answers a later COMMIT with a ROLLBACK tag and no error. The
     * commit is refused and rolled back rather than reported as one.
     */
    #[DataProvider('postgresDrivers')]
    public function test_a_postgres_commit_after_a_failed_statement_is_refused(string $driver): void
    {
        $db = self::makeClient($driver);
        self::createTable($db, $driver, 'tx_abort');

        $transaction = $db->beginTransaction();
        $transaction->execute('INSERT INTO tx_abort (s) VALUES (?)', ['before']);

        try {
            $transaction->query('SELECT * FROM a_table_that_does_not_exist');
            self::fail('Expected the failing statement to throw.');
        } catch (QueryException) {
        }

        try {
            $transaction->commit();
            self::fail('Expected the commit to be refused.');
        } catch (TransactionException $e) {
            self::assertStringContainsString('Commit refused', $e->getMessage());
        }

        self::assertFalse($transaction->isActive());
        self::assertSame(0, self::rowCount($db, 'tx_abort'));

        $db->query('DROP TABLE tx_abort');
        $db->close();
    }

    /**
     * PDO::inTransaction() reads the transaction status the server sent
     * with the last packet, so MySQL's implicit DDL commit is visible:
     * the next statement is refused instead of silently running in
     * autocommit, and the client's root link works again.
     */
    public function test_pdo_mysql_sees_a_transaction_ddl_committed_implicitly(): void
    {
        $db = self::makeClient('pdo-mysql');
        self::createTable($db, 'pdo-mysql', 'tx_ddl');

        $transaction = $db->beginTransaction();
        $transaction->query('CREATE TABLE tx_ddl_side (id INT PRIMARY KEY)');

        try {
            $transaction->execute('INSERT INTO tx_ddl (s) VALUES (?)', ['after-ddl']);
            self::fail('Expected the ended transaction to refuse the statement.');
        } catch (TransactionException $e) {
            self::assertStringContainsString('no longer open', $e->getMessage());
        }

        self::assertFalse($transaction->isActive());
        $db->execute('INSERT INTO tx_ddl (s) VALUES (?)', ['root']);
        self::assertSame(1, self::rowCount($db, 'tx_ddl'));

        $db->query('DROP TABLE tx_ddl_side');
        $db->query('DROP TABLE tx_ddl');
        $db->close();
    }

    /**
     * Disposal runs in the request's own context while the Fiber that
     * began a leaked transaction may be parked, so close() from another
     * Fiber ends it without putting a ROLLBACK on a connection its owner
     * may be using. The server discards the work with the session.
     */
    #[DataProvider('drivers')]
    public function test_a_foreign_fiber_close_discards_the_work(string $driver): void
    {
        $db = self::makeClient($driver);
        self::createTable($db, $driver, 'tx_close');

        $transaction = $db->beginTransaction();
        $transaction->execute('INSERT INTO tx_close (s) VALUES (?)', ['leaked']);

        $caught = null;
        $fiber = new Fiber(static function () use ($transaction, &$caught): void {
            try {
                $transaction->close();
            } catch (Throwable $e) {
                $caught = $e;
            }
        });
        $fiber->start();

        self::assertNull($caught);
        self::assertFalse($transaction->isActive());

        // The client is off the discarded session either way — a pool
        // opens a replacement, a PDO client opens one on its next call
        // — so the read below runs on a session that never saw the
        // abandoned transaction.
        self::assertSame(0, self::rowCount($db, 'tx_close'));
        $db->query('DROP TABLE tx_close');
        $db->close();
    }

    /**
     * A PDO client holds one connection, so discarding it has to close
     * the physical session: otherwise the server keeps the abandoned
     * transaction, and the locks it took, until something times out. The
     * completed transaction object stays reachable here, which is the
     * case that would keep the session open — a PDOStatement holds its
     * connection as surely as the handle does — and a fresh client then
     * takes the same lock, under a short server-side wait so a session
     * that survived fails this rather than hanging on it.
     */
    #[DataProvider('pdoDrivers')]
    public function test_a_discarded_pdo_transaction_releases_its_locks(string $driver): void
    {
        $db = self::makeClient($driver);
        self::createTable($db, $driver, 'tx_locks');
        $db->execute('INSERT INTO tx_locks (s) VALUES (?)', ['locked']);

        $transaction = $db->beginTransaction();
        $transaction->execute('UPDATE tx_locks SET s = ? WHERE s = ?', ['taken', 'locked']);

        new Fiber(static fn () => $transaction->close())->start();
        self::assertFalse($transaction->isActive());

        $reader = self::makeClient($driver);
        $reader->query(self::isMysql($driver) ? 'SET SESSION innodb_lock_wait_timeout = 3' : "SET lock_timeout = '3s'");
        self::assertSame('locked', $reader->query('SELECT s FROM tx_locks FOR UPDATE')->fetchRow()['s']);

        $reader->query('DROP TABLE tx_locks');
        $reader->close();
        $db->close();
    }

    /**
     * Transaction control sent as raw SQL ends the transaction on the
     * server. Both Postgres drivers see that — libpq tracks the status
     * the server sent with its last message, and PDO reads the same
     * thing — so the write after it is refused rather than committed on
     * its own in autocommit.
     */
    #[DataProvider('postgresDrivers')]
    public function test_a_raw_commit_ends_the_transaction(string $driver): void
    {
        $db = self::makeClient($driver);
        self::createTable($db, $driver, 'tx_raw');

        $transaction = $db->beginTransaction();
        $transaction->execute('INSERT INTO tx_raw (s) VALUES (?)', ['inside']);
        $transaction->query('COMMIT');

        self::assertFalse($transaction->isActive());

        try {
            $transaction->execute('INSERT INTO tx_raw (s) VALUES (?)', ['after']);
            self::fail('Expected the ended transaction to refuse the write.');
        } catch (TransactionException $e) {
            self::assertStringContainsString('no longer open', $e->getMessage());
        }

        self::assertSame(1, self::rowCount($db, 'tx_raw'));

        $db->query('DROP TABLE tx_raw');
        $db->close();
    }

    /**
     * Disposal does not wait for the owner to come back from the wire:
     * close() lands while its statement is still running, and the
     * connection is taken out of service under it. The owner is settled
     * with the acknowledgement it has — none — rather than left
     * suspended on a connection nothing will answer for.
     */
    #[DataProvider('nativeDrivers')]
    public function test_a_foreign_close_settles_a_statement_in_flight(string $driver): void
    {
        $db = self::makeClient($driver);
        self::createTable($db, $driver, 'tx_flight');

        $transaction = null;
        $caught = null;
        $onTheWire = false;
        $sleep = self::isMysql($driver) ? 'SELECT SLEEP(1)' : 'SELECT pg_sleep(1)';

        $owner = new Fiber(static function () use ($db, $sleep, &$transaction, &$caught, &$onTheWire): void {
            $transaction = $db->beginTransaction();
            $transaction->execute('INSERT INTO tx_flight (s) VALUES (?)', ['leaked']);
            $onTheWire = true;

            try {
                $transaction->query($sleep);
            } catch (Throwable $e) {
                $caught = $e;
            }
        });

        // Closing from an event-loop callback is the disposal hook's own
        // position — another Fiber, while the owner is parked. It waits
        // for the statement to be on the wire rather than for a length
        // of time, and gives up after five seconds so a Fiber that never
        // got there fails this rather than holding the loop open.
        $ticks = 0;
        EventLoop::repeat(0.01, static function (string $id) use (&$transaction, &$onTheWire, &$ticks): void {
            if (!$onTheWire && ++$ticks < 500) {
                return;
            }

            EventLoop::cancel($id);
            $transaction?->close();
        });

        $owner->start();
        EventLoop::run();

        self::assertInstanceOf(ConnectionException::class, $caught);
        self::assertStringContainsString('unknown', $caught->getMessage());
        self::assertInstanceOf(SqlTransaction::class, $transaction);
        self::assertFalse($transaction->isActive());

        // The pool replaced the connection, and the server discarded the
        // transaction with the session it was closed on.
        self::assertSame(1, (int) $db->query('SELECT 1 AS n')->fetchRow()['n']);
        $reader = self::makeClient($driver);
        self::assertSame(0, self::rowCount($reader, 'tx_flight'));
        $reader->query('DROP TABLE tx_flight');
        $reader->close();
        $db->close();
    }

    /**
     * MySQL resolves a deadlock by rolling one transaction back whole
     * and reporting it on the statement that lost, with error number
     * 1213. That number rides on the QueryException, the transaction
     * settles there, and what the caller tries next is refused instead
     * of running in autocommit.
     */
    public function test_a_deadlock_ends_the_transaction_the_server_rolled_back(): void
    {
        $db = self::makeClient('mysqli-async');
        $db->query('DROP TABLE IF EXISTS tx_deadlock');
        $db->query('CREATE TABLE tx_deadlock (id INT PRIMARY KEY, v INT NOT NULL) ENGINE=InnoDB');
        $db->execute('INSERT INTO tx_deadlock (id, v) VALUES (1, 0), (2, 0)');

        /** @var list<?array{QueryException, ?TransactionException, bool}> $outcomes */
        $outcomes = self::concurrently([
            static fn (): ?array => self::contend($db, 1, 2, 0.0),
            static fn (): ?array => self::contend($db, 2, 1, 0.05),
        ]);

        $lost = \array_values(\array_filter($outcomes));
        self::assertCount(1, $lost, 'Exactly one of the two transactions loses the deadlock.');
        [$failure, $refused, $stillActive] = $lost[0];

        self::assertStringContainsString('Deadlock', $failure->getMessage());
        self::assertSame(1213, $failure->getCode());
        self::assertFalse($stillActive);
        self::assertInstanceOf(TransactionException::class, $refused);
        self::assertStringContainsString('no longer open', $refused->getMessage());

        $db->query('DROP TABLE tx_deadlock');
        $db->close();
    }

    /**
     * The ordinary case the settling must not break: a statement the
     * server refused without ending the transaction. On MySQL the
     * transaction stays open and the write after it still belongs to it.
     * On Postgres the whole block is aborted, so that write is refused
     * by the server too, and nothing commits.
     */
    #[DataProvider('drivers')]
    public function test_a_write_after_a_failed_statement(string $driver): void
    {
        $db = self::makeClient($driver);
        self::createTable($db, $driver, 'tx_after_error');

        $transaction = $db->beginTransaction();
        $transaction->execute('INSERT INTO tx_after_error (s) VALUES (?)', ['first']);

        try {
            $transaction->query('SELECT * FROM a_table_that_does_not_exist');
            self::fail('Expected the failing statement to throw.');
        } catch (QueryException) {
        }

        self::assertTrue($transaction->isActive());

        if (self::isMysql($driver)) {
            $transaction->execute('INSERT INTO tx_after_error (s) VALUES (?)', ['second']);
            $transaction->commit();
            self::assertSame(2, self::rowCount($db, 'tx_after_error'));
        } else {
            try {
                $transaction->execute('INSERT INTO tx_after_error (s) VALUES (?)', ['second']);
                self::fail('Expected the aborted transaction to refuse the write.');
            } catch (QueryException) {
            }

            $transaction->rollback();
            self::assertSame(0, self::rowCount($db, 'tx_after_error'));
        }

        $db->query('DROP TABLE tx_after_error');
        $db->close();
    }

    /**
     * A lock-wait timeout (1205) is as terminal for the transaction's
     * connection as a deadlock is: `innodb_rollback_on_timeout` decides
     * whether the server rolled back the statement or the whole
     * transaction, and nothing in the error says which. Both drivers
     * therefore end the transaction and hand its session back for the
     * server to discard the work with — the pool opening a replacement,
     * the PDO client opening one on its next call.
     */
    #[DataProvider('mysqlDrivers')]
    public function test_a_lock_wait_timeout_ends_the_transaction_and_discards_its_connection(string $driver): void
    {
        $holder = self::makeClient($driver);
        self::createTable($holder, $driver, 'tx_lock_wait');
        $holder->execute('INSERT INTO tx_lock_wait (s) VALUES (?)', ['locked']);

        $db = self::makeClient($driver, new ConnectionOptions(maxConnections: 1));
        $held = null;
        $transaction = null;

        try {
            $db->query('SET SESSION innodb_lock_wait_timeout = 1');
            $before = self::mysqlConnectionId($db);

            $held = $holder->beginTransaction();
            $held->execute('UPDATE tx_lock_wait SET s = ? WHERE s = ?', ['held', 'locked']);

            $transaction = $db->beginTransaction();

            try {
                $transaction->execute('UPDATE tx_lock_wait SET s = ? WHERE s = ?', ['mine', 'locked']);
                self::fail('Expected the lock wait to time out.');
            } catch (QueryException $e) {
                self::assertSame(1205, $e->getCode());
            }

            self::assertFalse($transaction->isActive());

            try {
                $transaction->query('SELECT 1');
                self::fail('Expected the ended transaction to refuse the statement.');
            } catch (TransactionException $e) {
                self::assertStringContainsString('no longer open', $e->getMessage());
            }

            self::assertNotSame($before, self::mysqlConnectionId($db));
        } finally {
            // Whatever the assertions did, the row lock has to go before
            // the DROP below, which would otherwise wait on it.
            $transaction?->close();
            $held?->close();
            $db->close();
            $holder->query('DROP TABLE tx_lock_wait');
            $holder->close();
        }
    }

    /**
     * A request can end with the owner suspended on a COMMIT the server
     * has not answered yet — here a deferred constraint trigger holds
     * the commit open, which is an ordinary thing for a schema to do.
     * Until the connection is handed back, the transaction is still the
     * disposal hook's to close, and closing it takes the connection out
     * from under the commit. One release, one span, and an outcome of
     * unknown: the server may well have applied it.
     */
    public function test_a_commit_interrupted_from_another_fiber_settles_once_as_unknown(): void
    {
        $db = self::makeClient('pgsql-async', new ConnectionOptions(maxConnections: 2));
        self::createSlowCommitTable($db);

        $telemetry = $this->createMock(TelemetryInterface::class);
        $telemetry->method('transactionStarted')->willReturn('token');
        $telemetry->expects(self::once())->method('transactionEnded')->with('token', 'unknown');
        Telemetry::global()->swap($telemetry);

        $transaction = null;
        $caught = null;
        $committing = false;
        $activeWhileCommitting = null;

        $owner = new Fiber(static function () use ($db, &$transaction, &$caught, &$committing): void {
            $transaction = $db->beginTransaction();
            $transaction->execute('INSERT INTO tx_slow_commit (s) VALUES (?)', ['pending']);
            $committing = true;

            try {
                $transaction->commit();
            } catch (Throwable $e) {
                $caught = $e;
            }
        });

        // The disposal hook's own position: an event-loop callback,
        // while the owner is parked on the wire. It waits for the COMMIT
        // to be in flight rather than for a length of time, and gives up
        // after five seconds so a Fiber that never got there fails this
        // rather than holding the loop open.
        $ticks = 0;
        EventLoop::repeat(0.01, static function (string $id) use (&$transaction, &$committing, &$ticks, &$activeWhileCommitting): void {
            if (!$committing && ++$ticks < 500) {
                return;
            }

            EventLoop::cancel($id);
            $activeWhileCommitting = $transaction?->isActive();
            $transaction?->close();
        });

        $owner->start();
        EventLoop::run();

        self::assertTrue($activeWhileCommitting, 'A commit still on the wire is the disposal hook\'s to close.');
        self::assertInstanceOf(ConnectionException::class, $caught);
        self::assertInstanceOf(SqlTransaction::class, $transaction);
        self::assertFalse($transaction->isActive());

        // The pool replaced the connection it lost.
        self::assertSame(1, (int) $db->query('SELECT 1 AS n')->fetchRow()['n']);
        $db->query('DROP TABLE tx_slow_commit');
        $db->query('DROP FUNCTION tx_slow_commit_wait()');
        $db->close();
    }

    /**
     * One side of the deadlock: take the row $first holds, wait for the
     * other side to take its own, then reach for $second. Answers null
     * for the transaction that won, and what the loser saw otherwise.
     *
     * @return ?array{QueryException, ?TransactionException, bool}
     */
    private static function contend(SqlLink $db, int $first, int $second, float $delay): ?array
    {
        self::pause($delay);

        $transaction = $db->beginTransaction();
        $transaction->execute('UPDATE tx_deadlock SET v = v + 1 WHERE id = ?', [$first]);
        self::pause(0.2);

        try {
            $transaction->execute('UPDATE tx_deadlock SET v = v + 1 WHERE id = ?', [$second]);
        } catch (QueryException $failure) {
            $refused = null;

            try {
                $transaction->execute('UPDATE tx_deadlock SET v = v + 1 WHERE id = ?', [$first]);
            } catch (TransactionException $e) {
                $refused = $e;
            }

            return [$failure, $refused, $transaction->isActive()];
        }

        $transaction->commit();

        return null;
    }

    /** Yields the Fiber for $seconds, so the two sides interleave. */
    private static function pause(float $seconds): void
    {
        if ($seconds <= 0.0) {
            return;
        }

        $suspension = EventLoop::getSuspension();
        EventLoop::delay($seconds, static fn () => $suspension->resume());
        $suspension->suspend();
    }

    /**
     * A table whose every insert is checked by a deferred constraint
     * trigger that sleeps — so the COMMIT, not the INSERT, is what takes
     * a second on the server.
     */
    private static function createSlowCommitTable(SqlLink $db): void
    {
        $db->query('DROP TABLE IF EXISTS tx_slow_commit');
        $db->query('CREATE TABLE tx_slow_commit (id SERIAL PRIMARY KEY, s VARCHAR(32) NOT NULL)');
        $db->query(
            'CREATE OR REPLACE FUNCTION tx_slow_commit_wait() RETURNS trigger LANGUAGE plpgsql '
            . "AS 'BEGIN PERFORM pg_sleep(1); RETURN NULL; END'",
        );
        $db->query(
            'CREATE CONSTRAINT TRIGGER tx_slow_commit_hold AFTER INSERT ON tx_slow_commit '
            . 'DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION tx_slow_commit_wait()',
        );
    }

    private static function mysqlConnectionId(SqlLink $db): int
    {
        return (int) $db->query('SELECT CONNECTION_ID() AS id')->fetchRow()['id'];
    }

    private static function createTable(SqlLink $db, string $driver, string $table): void
    {
        $db->query("DROP TABLE IF EXISTS {$table}");
        $db->query("CREATE TABLE {$table} (id " . self::autoIncrementColumn($driver) . ', s VARCHAR(32) NOT NULL)');
    }

    private static function rowCount(SqlLink $db, string $table = 'tx_own'): int
    {
        return (int) $db->query("SELECT COUNT(*) AS c FROM {$table}")->fetchRow()['c'];
    }
}
