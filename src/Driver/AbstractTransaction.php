<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Closure;
use Fiber;
use Kinetis\Instrumentation\Telemetry;
use Kinetis\Persistence\Contract\MysqlTransaction;
use Kinetis\Persistence\Contract\PostgresTransaction;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Contract\SqlTransaction;
use Kinetis\Persistence\Exception\ConnectionException;
use Kinetis\Persistence\Exception\QueryException;
use Kinetis\Persistence\Exception\TransactionException;
use Throwable;

/**
 * The transaction state machine every driver shares.
 *
 * A transaction belongs to the Fiber that began it (the main context is
 * an owner like any other). query(), execute(), commit() and rollback()
 * are owner operations and throw {@see TransactionException} from any
 * other Fiber: the connection carries one statement at a time, so a
 * second Fiber dispatching on it corrupts both. close() is the
 * lifecycle escape hatch and is callable from anywhere — it is what
 * `TransactionGuard::rollbackDangling()` runs at request-scope
 * disposal, where the owner Fiber may be parked or gone.
 *
 * Two flags, because they change at different moments:
 *
 * - **active** — whether another statement is accepted. It goes false
 *   the moment a COMMIT or ROLLBACK is sent.
 * - **settled** — whether the connection has been handed back or
 *   discarded and the span closed. It goes true only once the finish
 *   has been answered, so between the two the transaction still owns a
 *   connection with a statement on it. That window is exactly where a
 *   request ending under a suspended owner has to reach, which is why
 *   {@see isActive()} — the disposal hook's own question — reports the
 *   transaction live until it is settled.
 *
 * Settling happens exactly once. Every way out reaches it: commit,
 * rollback, close, losing the connection mid-statement, a failure that
 * takes the connection with it, and the server ending the transaction
 * underneath the object, which each driver notices from its own local
 * state after every statement and before the next one. A foreign close()
 * landing on a finish already in flight settles it there, and the owner
 * resuming with the connection gone settles nothing a second time.
 *
 * The outcome recorded on the span is the truth and nothing softer:
 * `commit` only for a COMMIT the server acknowledged, `rollback` only
 * for a ROLLBACK it acknowledged, and `unknown` for everything else —
 * a connection lost, a discard, a finish nothing answered, a transaction
 * the server ended on its own.
 *
 * The parameter pre-flight is shared here too, for the same reason the
 * state check is: a subclass reaching its pinned connection before the
 * argument list has been settled would decide a caller's mistake
 * differently on each driver. {@see execute()} runs it and hands
 * {@see runWithParams()} the outcome, so no subclass is in a position
 * to dispatch first.
 *
 * @internal
 */
abstract class AbstractTransaction implements SqlTransaction
{
    private const string INTERRUPTED_MESSAGE = 'The connection was taken out of service while this transaction was finishing; whether the server applied it is unknown';

    private readonly ?Fiber $owner;

    private bool $active = true;

    private bool $settled = false;

    /**
     * Postgres aborts the whole transaction on any statement error and
     * answers a later COMMIT with a ROLLBACK tag and no error, so the
     * failure is recorded here and {@see commit()} refuses.
     */
    private bool $aborted = false;

    private mixed $telemetryToken = null;

    /** Built on first use: a transaction with no bound parameters needs none. */
    private ?SqlParamPreflight $preflight = null;

    /**
     * Records the owning Fiber and the begin moment for instrumentation.
     * Every concrete constructor calls this, since PHP never runs a
     * parent constructor implicitly.
     */
    protected function __construct()
    {
        $this->owner = Fiber::getCurrent();
        $this->telemetryToken = Telemetry::global()->transactionStarted(
            $this instanceof MysqlTransaction ? 'mysql' : 'postgresql',
        );
    }

    #[\Override]
    public function query(string $sql): SqlResult
    {
        $this->assertOwner();
        $this->assertActive();

        return $this->dispatch(fn (): SqlResult => $this->run($sql));
    }

    #[\Override]
    public function execute(string $sql, array $params = []): SqlResult
    {
        $this->assertOwner();
        $this->assertActive();

        // Ahead of every subclass's dispatch, so a refused argument
        // list sends no statement down the pinned connection — and,
        // being a caller's own mistake rather than a server's answer,
        // leaves the transaction usable.
        $this->preflight ??= new SqlParamPreflight(
            $this instanceof MysqlTransaction ? SqlDialect::Mysql : SqlDialect::Postgres,
        );
        $query = $this->preflight->run($sql, $params);

        return $this->dispatch(fn (): SqlResult => $this->runWithParams($query));
    }

    #[\Override]
    public function beginTransaction(): SqlTransaction
    {
        throw new TransactionException('Nested transactions are not supported by ' . $this->driverLabel());
    }

    #[\Override]
    public function commit(): void
    {
        $this->assertOwner();
        $this->assertActive();

        if ($this->aborted) {
            // Postgres has already discarded this transaction's work.
            // Ending it as a rollback and saying so is the only honest
            // answer; the server would report the COMMIT as successful.
            $this->end(false);

            throw new TransactionException(
                'Commit refused: a statement in this transaction failed, so the server has already '
                . 'aborted it. The transaction is rolled back.',
            );
        }

        $this->end(true);
    }

    /**
     * Rolls back an active transaction, and does nothing to one that has
     * already ended — so `catch (Throwable) { $tx->rollback(); throw $e; }`
     * is correct without an isActive() pre-check.
     */
    #[\Override]
    public function rollback(): void
    {
        $this->assertOwner();
        // A transaction the server already ended settles without
        // sending ROLLBACK: there is nothing left on the connection to
        // roll back, and the statement would run in autocommit.
        $this->settleIfServerEnded();

        if (!$this->active) {
            return;
        }

        $this->end(false);
    }

    /**
     * Whether this transaction still holds anything — a connection, an
     * unclosed span — and so whether the disposal hook has work to do.
     * A finish still on the wire counts: its owner is suspended, and
     * nothing else will hand that connection back.
     *
     * Where the server has ended the transaction underneath the object,
     * this is also the moment that becomes visible. Answering "no"
     * without settling would leave the connection's ownership and the
     * span held by an object nothing will touch again, since a caller
     * trusting the answer never closes it.
     */
    #[\Override]
    public function isActive(): bool
    {
        $this->settleIfServerEnded();

        return !$this->settled;
    }

    /**
     * Lifecycle cleanup, callable from any Fiber — the disposal hook
     * `TransactionGuard::rollbackDangling()` runs.
     *
     * On the owning Fiber this is an ordinary rollback. From any other
     * Fiber it sends nothing: the owner may be mid-statement on the
     * connection, and a concurrent ROLLBACK would corrupt both. The
     * transaction ends and its connection is discarded instead, so the
     * server rolls the work back with the session — and the driver
     * settles a statement of the owner's still in flight as that
     * connection goes, with no acknowledgement left to report.
     */
    #[\Override]
    public function close(): void
    {
        if ($this->settled) {
            return;
        }

        if (!$this->active) {
            // A COMMIT or ROLLBACK is on the wire with its owner
            // suspended on the answer. The connection goes, which is
            // what settles that owner, and the outcome is what it is.
            $this->settle(discard: true, outcome: 'unknown');

            return;
        }

        if (Fiber::getCurrent() !== $this->owner) {
            $this->end(null, discard: true);

            return;
        }

        $this->rollback();
    }

    #[\Override]
    public function isClosed(): bool
    {
        return !$this->isActive();
    }

    /** Executes complete SQL text on the pinned connection. */
    abstract protected function run(string $sql): SqlResult;

    /**
     * Executes a query the pre-flight has already settled on the pinned
     * connection.
     */
    abstract protected function runWithParams(PreflightedQuery $query): SqlResult;

    /**
     * Sends COMMIT or ROLLBACK on the pinned connection. Called at most
     * once, from {@see end()}, after the transaction is already marked
     * inactive — so a failing finish still leaves it closed — and never
     * for a transaction the connection no longer holds.
     */
    abstract protected function finish(bool $commit): void;

    /**
     * Hands the pinned connection back to whoever owns it, exactly once,
     * whether or not {@see finish()} ran or succeeded. $discard means the
     * connection must not be reused: it died, the transaction was ended
     * without rolling it back on the wire, or {@see finish()} failed and
     * left its outcome unknown.
     */
    abstract protected function release(bool $discard): void;

    /**
     * Whether the driver still reports this transaction open on its
     * connection, from local state only — never a round trip. The
     * default is "the object's own state is the only state there is";
     * every concrete transaction overrides it with what its driver can
     * actually see, which differs by driver and is documented on each.
     */
    protected function stillOnConnection(): bool
    {
        return true;
    }

    /**
     * Whether $failure leaves the pinned connection unfit to carry this
     * transaction any further. The default is no: a statement the server
     * refused is ordinarily just a failed statement, and the transaction
     * goes on. MySQL's lock-wait timeout and deadlock are the exception
     * — see {@see MysqlLockFailure}.
     */
    protected function isTerminalFailure(QueryException $failure): bool
    {
        return false;
    }

    /** Names the driver in the nested-transaction error. */
    abstract protected function driverLabel(): string;

    /**
     * Runs one statement on the pinned connection, recording the
     * outcomes that change the transaction's own state: on Postgres a
     * failed statement aborts everything the transaction has done,
     * losing the connection ends the transaction wherever the server is,
     * a failure the driver calls terminal takes the connection with it,
     * and any answer can leave the server holding no transaction at all
     * — which is settled here, whether the statement succeeded or
     * failed, so nothing that follows runs in autocommit believing it is
     * still inside one.
     */
    private function dispatch(Closure $statement): SqlResult
    {
        try {
            $result = $statement();
        } catch (QueryException $e) {
            if ($this instanceof PostgresTransaction) {
                $this->aborted = true;
            }

            if ($this->isTerminalFailure($e)) {
                $this->end(null, discard: true);
            } else {
                $this->settleIfServerEnded();
            }

            throw $e;
        } catch (ConnectionException $e) {
            // The session is gone, and with it the transaction. Nothing
            // can be sent on this connection, so it is abandoned rather
            // than rolled back.
            $this->end(null, discard: true);

            throw $e;
        }

        $this->settleIfServerEnded();

        return $result;
    }

    /**
     * Ends the transaction when the driver reports the server has
     * already done so, before anything else runs on the connection.
     * Nothing goes on the wire: the transaction is over wherever the
     * server is, and a ROLLBACK would run in autocommit. The connection
     * itself is healthy, so it is handed back rather than discarded.
     */
    private function settleIfServerEnded(): void
    {
        if (!$this->active || $this->stillOnConnection()) {
            return;
        }

        $this->end(null);
    }

    /**
     * The one terminal transition. $commit is true for COMMIT, false for
     * ROLLBACK, and null to end the transaction without sending anything
     * — the connection is gone, or it is being handed back to the server
     * to roll back with the session.
     *
     * Idempotent through $active, which is cleared here and nowhere
     * else. The finish it sends can suspend, and a close() reaching the
     * transaction in that window settles it from under this call: what
     * the owner came back with is still the caller's answer, but the
     * connection is not handed back a second time and the span is not
     * closed twice.
     */
    private function end(?bool $commit, bool $discard = false): void
    {
        if (!$this->active) {
            return;
        }

        $this->active = false;
        $failure = null;
        $acknowledged = $commit !== null;

        try {
            if ($commit !== null) {
                $this->finish($commit);
            }
        } catch (Throwable $e) {
            // A COMMIT or ROLLBACK that failed leaves both the
            // transaction's outcome and the connection's state unknown,
            // so the connection is discarded instead of handed back to
            // serve the next caller mid-transaction.
            $failure = $e;
            $acknowledged = false;
            $discard = true;
        }

        if ($this->settled) {
            throw $failure ?? new ConnectionException(self::INTERRUPTED_MESSAGE);
        }

        try {
            $this->settle($discard, match (true) {
                !$acknowledged => 'unknown',
                $commit === true => 'commit',
                default => 'rollback',
            });
        } catch (Throwable $e) {
            // A release that fails on top of a failed finish is
            // secondary — the finish failure is what the caller has to
            // act on. With no finish failure it is the whole story.
            $failure ??= $e;
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Hands the connection back and closes the span, exactly once.
     * Reached only with the transaction already refusing statements.
     *
     * @param 'commit'|'rollback'|'unknown' $outcome
     */
    private function settle(bool $discard, string $outcome): void
    {
        $this->settled = true;

        try {
            $this->release($discard);
        } finally {
            Telemetry::global()->transactionEnded($this->telemetryToken, $outcome);
        }
    }

    private function assertOwner(): void
    {
        if (Fiber::getCurrent() !== $this->owner) {
            throw new TransactionException(
                'A transaction may only be used from the Fiber that began it: it pins one connection, '
                . 'which carries one statement at a time. Run the work on the owning Fiber, or give the '
                . 'other Fiber its own transaction.',
            );
        }
    }

    private function assertActive(): void
    {
        $this->settleIfServerEnded();

        if ($this->active) {
            return;
        }

        throw new TransactionException(
            'The transaction is no longer open: it has been committed or rolled back, or the server '
            . 'ended it on its connection. MySQL does that implicitly for DDL, and to the loser of a '
            . 'deadlock or a lock-wait timeout. Keep DDL out of transactions, and begin a new '
            . 'transaction to retry work the server rolled back.',
        );
    }
}
