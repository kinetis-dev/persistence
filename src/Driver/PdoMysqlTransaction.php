<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Kinetis\Persistence\Contract\MysqlTransaction;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Contract\SqlTransaction;
use Kinetis\Persistence\Exception\QueryException;
use Kinetis\Persistence\Exception\TransactionException;
use PDO;
use PDOException;

/**
 * A transaction on {@see PdoMysqlClient} — PDO is a single connection,
 * so the transaction routes through the same handle with PDO's own
 * native transaction state.
 */
final class PdoMysqlTransaction implements MysqlTransaction
{
    private bool $active = true;

    public function __construct(
        private readonly PdoMysqlClient $client,
        private readonly PDO $pdo,
    ) {}

    public function query(string $sql): SqlResult
    {
        $this->assertActive();

        try {
            $statement = $this->pdo->query($sql);

            if ($statement === false) {
                throw new QueryException('Query failed', $sql);
            }
        } catch (PDOException $e) {
            throw new QueryException($e->getMessage(), $sql, $e);
        }

        return $this->client->buildResult($statement);
    }

    public function execute(string $sql, array $params = []): SqlResult
    {
        $this->assertActive();

        try {
            $statement = $this->pdo->prepare($sql);

            if ($statement === false) {
                throw new QueryException('Failed to prepare query', $sql);
            }

            $statement->execute(\array_values($params));
        } catch (PDOException $e) {
            throw new QueryException($e->getMessage(), $sql, $e);
        }

        return $this->client->buildResult($statement);
    }

    public function beginTransaction(): SqlTransaction
    {
        throw new TransactionException('Nested transactions are not supported by the PDO driver');
    }

    public function commit(): void
    {
        $this->finish(true);
    }

    public function rollback(): void
    {
        $this->finish(false);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function close(): void
    {
        if ($this->active) {
            $this->rollback();
        }
    }

    public function isClosed(): bool
    {
        return !$this->active;
    }

    private function finish(bool $commit): void
    {
        $this->assertActive();
        $this->active = false;

        try {
            $commit ? $this->pdo->commit() : $this->pdo->rollBack();
        } catch (PDOException $e) {
            throw new TransactionException(($commit ? 'Commit' : 'Rollback') . ' failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function assertActive(): void
    {
        if (!$this->active) {
            throw new TransactionException('The transaction has already been committed or rolled back');
        }
    }
}
