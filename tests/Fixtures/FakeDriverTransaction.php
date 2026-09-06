<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Fixtures;

use Closure;
use Kinetis\Persistence\Contract\MysqlTransaction;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Driver\AbstractTransaction;
use Kinetis\Persistence\Driver\BufferedSqlResult;
use Kinetis\Persistence\Driver\PreflightedQuery;
use Kinetis\Persistence\Exception\QueryException;
use Throwable;

/**
 * An AbstractTransaction owning no connection at all: it records what it
 * was asked to dispatch, what it was asked to finish with, and how often
 * it released, and can be told to fail a dispatch, a finish or a release
 * with any exception.
 *
 * It stands for the two native transactions in the state-machine tests.
 * MysqliAsyncTransaction and PgsqlAsyncTransaction pin a live connection
 * handed to them by their client — a mysqli, a PgSql\Connection — which
 * cannot exist without a reachable server. What their base class decides
 * on its own is ownership, one terminal transition, and how a statement's
 * outcome changes the transaction's state; all of that is here.
 *
 * The dialect marker is a subclass choice, since Postgres and MySQL
 * differ on what a failed statement does to the transaction.
 */
class FakeDriverTransaction extends AbstractTransaction implements MysqlTransaction
{
    public int $dispatches = 0;

    /** @var list<null|bool|int|float|string> What the last accepted call carried. */
    public array $lastValues = [];

    /** @var list<bool> One entry per finish(), true for COMMIT. */
    public array $finished = [];

    /** @var list<bool> One entry per release(), true when the connection was discarded. */
    public array $released = [];

    /** Thrown by the next dispatch instead of returning a result. */
    public ?Throwable $failNextDispatch = null;

    /**
     * Runs inside a dispatch, before it answers — the window where the
     * statement is on the wire and the owning Fiber is suspended.
     */
    public ?Closure $duringDispatch = null;

    /** Thrown by finish(), standing for a COMMIT/ROLLBACK the server refused. */
    public ?Throwable $failFinish = null;

    /**
     * Runs inside finish(), before it answers — the window where the
     * COMMIT is on the wire, the transaction accepts nothing further,
     * and its connection is still pinned.
     */
    public ?Closure $duringFinish = null;

    /** Thrown by release(), standing for a connection that cannot be handed back. */
    public ?Throwable $failRelease = null;

    /**
     * What stillOnConnection() reports. True is the native transactions'
     * own answer, always; false stands for what PdoTransaction reads
     * from PDO::inTransaction() once the server has ended the
     * transaction underneath the object.
     */
    public bool $onConnection = true;

    /**
     * What isTerminalFailure() reports — the MySQL transactions' answer
     * for a lock-wait timeout or a deadlock ({@see MysqlLockFailure}).
     */
    public bool $terminal = false;

    public function __construct()
    {
        parent::__construct();
    }

    #[\Override]
    protected function run(string $sql): SqlResult
    {
        return $this->dispatched();
    }

    #[\Override]
    protected function runWithParams(PreflightedQuery $query): SqlResult
    {
        $this->lastValues = $query->values;

        return $this->dispatched();
    }

    #[\Override]
    protected function finish(bool $commit): void
    {
        $this->finished[] = $commit;

        if ($this->duringFinish !== null) {
            ($this->duringFinish)();
        }

        if ($this->failFinish !== null) {
            throw $this->failFinish;
        }
    }

    #[\Override]
    protected function release(bool $discard): void
    {
        $this->released[] = $discard;

        if ($this->failRelease !== null) {
            throw $this->failRelease;
        }
    }

    #[\Override]
    protected function stillOnConnection(): bool
    {
        return $this->onConnection;
    }

    #[\Override]
    protected function isTerminalFailure(QueryException $failure): bool
    {
        return $this->terminal;
    }

    #[\Override]
    protected function driverLabel(): string
    {
        return 'the fake test driver';
    }

    private function dispatched(): SqlResult
    {
        $this->dispatches++;

        if ($this->duringDispatch !== null) {
            ($this->duringDispatch)();
        }

        if ($this->failNextDispatch !== null) {
            $failure = $this->failNextDispatch;
            $this->failNextDispatch = null;

            throw $failure;
        }

        return new BufferedSqlResult([], 0, null);
    }
}
