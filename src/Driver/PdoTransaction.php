<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Closure;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Exception\QueryException;
use Kinetis\Persistence\Exception\TransactionException;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Shared body for the PDO transactions — PDO is a single connection, so
 * a transaction routes through the same handle with PDO's own native
 * transaction state. Dialect finals only tag the marker interface.
 *
 * @internal
 */
abstract class PdoTransaction extends AbstractTransaction
{
    /**
     * Prepared statements memoized for this transaction's lifetime —
     * the same {@see PdoStatementCache} a client keeps for its
     * connection, scoped here to the transaction, because that is how
     * long this object owns the PDO handle.
     */
    private readonly PdoStatementCache $statements;

    /**
     * @param Closure(PDOStatement): SqlResult $buildResult The owning
     *     client's result construction (dialects differ on lastInsertId).
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly Closure $buildResult,
    ) {
        $this->statements = new PdoStatementCache();
        $this->telemetryBegin();
    }

    #[\Override]
    protected function run(string $sql): SqlResult
    {
        try {
            $statement = $this->pdo->query($sql);

            if ($statement === false) {
                throw new QueryException('Query failed', $sql);
            }
        } catch (PDOException $e) {
            throw new QueryException($e->getMessage(), $sql, $e);
        }

        return ($this->buildResult)($statement);
    }

    #[\Override]
    protected function runWithParams(PreflightedQuery $query): SqlResult
    {
        try {
            $statement = $this->statements->execute($this->pdo, $query);
        } catch (PDOException $e) {
            throw new QueryException($e->getMessage(), $query->sql, $e);
        }

        return ($this->buildResult)($statement);
    }

    #[\Override]
    protected function finish(bool $commit): void
    {
        try {
            $commit ? $this->pdo->commit() : $this->pdo->rollBack();
        } catch (PDOException $e) {
            throw new TransactionException(($commit ? 'Commit' : 'Rollback') . ' failed: ' . $e->getMessage(), 0, $e);
        }
    }

    #[\Override]
    protected function driverLabel(): string
    {
        return 'the PDO driver';
    }
}
