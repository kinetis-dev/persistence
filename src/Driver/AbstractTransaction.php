<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Kinetis\Instrumentation\Telemetry;
use Kinetis\Persistence\Contract\MysqlTransaction;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Contract\SqlTransaction;
use Kinetis\Persistence\Exception\TransactionException;

/**
 * The transaction state machine every driver shares: active until
 * exactly one commit() or rollback(), close() rolls back a still-active
 * transaction (the TransactionGuard::rollbackDangling() contract), and
 * operating on a finished transaction throws. Subclasses supply only
 * how SQL actually reaches their pinned connection ({@see run()}) and
 * what finishing does with it ({@see finish()}).
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
    private bool $active = true;

    private mixed $telemetryToken = null;

    /** Built on first use: a transaction with no bound parameters needs none. */
    private ?SqlParamPreflight $preflight = null;

    /**
     * Marks the transaction's begin moment for instrumentation — called
     * from each concrete constructor, since PHP never runs a parent
     * constructor implicitly.
     */
    protected function telemetryBegin(): void
    {
        $this->telemetryToken = Telemetry::global()->transactionStarted(
            $this instanceof MysqlTransaction ? 'mysql' : 'postgresql',
        );
    }

    #[\Override]
    public function query(string $sql): SqlResult
    {
        $this->assertActive();

        return $this->run($sql);
    }

    #[\Override]
    public function execute(string $sql, array $params = []): SqlResult
    {
        $this->assertActive();

        // Ahead of every subclass's dispatch, so a refused argument
        // list sends no statement down the pinned connection.
        $this->preflight ??= new SqlParamPreflight(
            $this instanceof MysqlTransaction ? SqlDialect::Mysql : SqlDialect::Postgres,
        );

        return $this->runWithParams($this->preflight->run($sql, $params));
    }

    #[\Override]
    public function beginTransaction(): SqlTransaction
    {
        throw new TransactionException('Nested transactions are not supported by ' . $this->driverLabel());
    }

    #[\Override]
    public function commit(): void
    {
        $this->assertActive();
        $this->active = false;

        try {
            $this->finish(true);
        } finally {
            Telemetry::global()->transactionEnded($this->telemetryToken, 'commit');
        }
    }

    #[\Override]
    public function rollback(): void
    {
        $this->assertActive();
        $this->active = false;

        try {
            $this->finish(false);
        } finally {
            Telemetry::global()->transactionEnded($this->telemetryToken, 'rollback');
        }
    }

    #[\Override]
    public function isActive(): bool
    {
        return $this->active;
    }

    #[\Override]
    public function close(): void
    {
        if ($this->active) {
            $this->rollback();
        }
    }

    #[\Override]
    public function isClosed(): bool
    {
        return !$this->active;
    }

    /** Executes complete SQL text on the pinned connection. */
    abstract protected function run(string $sql): SqlResult;

    /**
     * Executes a query the pre-flight has already settled on the pinned
     * connection.
     */
    abstract protected function runWithParams(PreflightedQuery $query): SqlResult;

    /**
     * Completes the transaction on the pinned connection — COMMIT or
     * ROLLBACK plus whatever handing the connection back requires.
     * Called exactly once, after the transaction is already marked
     * inactive (so a failing finish still leaves it closed).
     */
    abstract protected function finish(bool $commit): void;

    /** Names the driver in the nested-transaction error. */
    abstract protected function driverLabel(): string;

    private function assertActive(): void
    {
        if (!$this->active) {
            throw new TransactionException('The transaction has already been committed or rolled back');
        }
    }
}
