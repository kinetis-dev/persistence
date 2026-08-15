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
     * @param Closure(PDOStatement): SqlResult $buildResult The owning
     *     client's result construction (dialects differ on lastInsertId).
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly Closure $buildResult,
    ) {}

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

    protected function runWithParams(string $sql, array $params): SqlResult
    {
        try {
            $statement = $this->pdo->prepare($sql);

            if ($statement === false) {
                throw new QueryException('Failed to prepare query', $sql);
            }

            PdoParamBinder::bind($statement, $params);
            $statement->execute();
        } catch (PDOException $e) {
            throw new QueryException($e->getMessage(), $sql, $e);
        }

        return ($this->buildResult)($statement);
    }

    protected function finish(bool $commit): void
    {
        try {
            $commit ? $this->pdo->commit() : $this->pdo->rollBack();
        } catch (PDOException $e) {
            throw new TransactionException(($commit ? 'Commit' : 'Rollback') . ' failed: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function driverLabel(): string
    {
        return 'the PDO driver';
    }
}
