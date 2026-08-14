<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Exception\QueryException;
use PDO;
use PDOException;
use PDOStatement;

/**
 * The execution body both PDO clients share — everything except how the
 * connection is opened (DSN/attributes) and how a result is built
 * (dialects differ on lastInsertId), which stay with the client.
 *
 * @internal
 *
 * @phpstan-require-implements \Kinetis\Persistence\Contract\SqlLink
 */
trait PdoExecutionTrait
{
    private ?PDO $pdo = null;

    private bool $closed = false;

    public function query(string $sql): SqlResult
    {
        try {
            $statement = $this->connection()->query($sql);

            if ($statement === false) {
                throw new QueryException('Query failed', $sql);
            }
        } catch (PDOException $e) {
            throw new QueryException($e->getMessage(), $sql, $e);
        }

        return $this->buildResult($statement);
    }

    public function execute(string $sql, array $params = []): SqlResult
    {
        try {
            $statement = $this->connection()->prepare($sql);

            if ($statement === false) {
                throw new QueryException('Failed to prepare query', $sql);
            }

            $statement->execute(\array_values($params));
        } catch (PDOException $e) {
            throw new QueryException($e->getMessage(), $sql, $e);
        }

        return $this->buildResult($statement);
    }

    public function close(): void
    {
        $this->closed = true;
        $this->pdo = null;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** Starts PDO's native transaction on the lazily-opened connection. */
    private function beginPdoTransaction(): PDO
    {
        try {
            $this->connection()->beginTransaction();
        } catch (PDOException $e) {
            throw new QueryException('Failed to begin transaction: ' . $e->getMessage(), '', $e);
        }

        return $this->connection();
    }

    /** Opens (or returns) the one lazily-created PDO connection. */
    abstract private function connection(): PDO;

    /** Builds the buffered result — dialects differ on lastInsertId. */
    abstract public function buildResult(PDOStatement $statement): BufferedSqlResult;
}
