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
     * Prepared statements memoized for this transaction's lifetime, the
     * same way PdoExecutionTrait does for a connection's, and for the
     * same reason: native prepares make every prepare() its own round
     * trip, so without reuse a transaction issuing one statement N times
     * pays 2N round trips instead of N+1. Scoped to the transaction
     * because that is how long this object owns the PDO handle.
     *
     * @var array<string, PDOStatement>
     */
    private array $statements = [];

    /**
     * @param Closure(PDOStatement): SqlResult $buildResult The owning
     *     client's result construction (dialects differ on lastInsertId).
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly Closure $buildResult,
    ) {
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
    protected function runWithParams(string $sql, array $params): SqlResult
    {
        try {
            $statement = $this->statements[$sql] ?? null;

            if ($statement === null) {
                // Bounded the same way the client's cache is: SQL built by
                // interpolating values would otherwise grow it without
                // limit, while a bounded set of parameterized statements
                // stays at exactly one prepare each.
                if (\count($this->statements) >= 256) {
                    $this->statements = [];
                }

                $prepared = $this->pdo->prepare($sql);

                if ($prepared === false) {
                    throw new QueryException('Failed to prepare query', $sql);
                }

                $statement = $this->statements[$sql] = $prepared;
            }

            PdoParamBinder::bind($statement, $params);
            $statement->execute();
        } catch (PDOException $e) {
            throw new QueryException($e->getMessage(), $sql, $e);
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
