<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Amp\Postgres\PostgresResult;
use Amp\Postgres\PostgresStatement;
use Amp\Postgres\PostgresTransaction;
use Amp\Sql\SqlException;
use Amp\Sql\SqlQueryError;
use Amp\Sql\SqlTransactionError;
use Amp\Sql\SqlTransactionIsolation;
use Amp\Sql\SqlTransactionIsolationLevel;
use Closure;
use PDO;
use PDOException;

/**
 * A transaction on {@see PdoPgsqlClient} — same shape as
 * {@see PdoMysqlTransaction}.
 */
final class PdoPgsqlTransaction implements PostgresTransaction
{
    private bool $active = true;

    /** @var list<Closure(): void> */
    private array $onCommit = [];

    /** @var list<Closure(): void> */
    private array $onRollback = [];

    /** @var list<Closure(): void> */
    private array $onClose = [];

    public function __construct(
        private readonly PdoPgsqlClient $client,
        private readonly PDO $pdo,
    ) {}

    public function query(string $sql): PostgresResult
    {
        $this->assertActive();

        try {
            $statement = $this->pdo->query($sql);

            if ($statement === false) {
                throw new SqlQueryError('Query failed', $sql);
            }
        } catch (PDOException $e) {
            throw new SqlQueryError($e->getMessage(), $sql, $e);
        }

        return $this->client->buildResult($statement);
    }

    public function execute(string $sql, array $params = []): PostgresResult
    {
        $this->assertActive();

        try {
            $statement = $this->pdo->prepare($sql);

            if ($statement === false) {
                throw new SqlQueryError('Failed to prepare query', $sql);
            }

            $statement->execute(\array_values($params));
        } catch (PDOException $e) {
            throw new SqlQueryError($e->getMessage(), $sql, $e);
        }

        return $this->client->buildResult($statement);
    }

    public function prepare(string $sql): PostgresStatement
    {
        throw new SqlException('PdoPgsqlTransaction does not expose amphp statement objects — use execute()');
    }

    public function beginTransaction(): PostgresTransaction
    {
        throw new SqlTransactionError('Nested transactions are not supported by the PDO driver');
    }

    public function notify(string $channel, string $payload = ''): PostgresResult
    {
        $sql = 'NOTIFY ' . $this->quoteIdentifier($channel)
            . ($payload === '' ? '' : ', ' . $this->quoteLiteral($payload));

        return $this->query($sql);
    }

    public function quoteLiteral(string $data): string
    {
        return $this->client->quoteLiteral($data);
    }

    public function quoteIdentifier(string $name): string
    {
        return $this->client->quoteIdentifier($name);
    }

    public function escapeByteA(string $data): string
    {
        return $this->client->escapeByteA($data);
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

    public function getIsolation(): SqlTransactionIsolation
    {
        return SqlTransactionIsolationLevel::Committed;
    }

    public function getSavepointIdentifier(): ?string
    {
        return null;
    }

    public function onCommit(Closure $onCommit): void
    {
        $this->onCommit[] = $onCommit;
    }

    public function onRollback(Closure $onRollback): void
    {
        $this->onRollback[] = $onRollback;
    }

    public function onClose(Closure $onClose): void
    {
        if (!$this->active) {
            $onClose();

            return;
        }

        $this->onClose[] = $onClose;
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

    public function getLastUsedAt(): int
    {
        return $this->client->getLastUsedAt();
    }

    private function finish(bool $commit): void
    {
        $this->assertActive();
        $this->active = false;

        try {
            $commit ? $this->pdo->commit() : $this->pdo->rollBack();
        } catch (PDOException $e) {
            foreach ($this->onClose as $onClose) {
                $onClose();
            }

            throw new SqlQueryError(($commit ? 'Commit' : 'Rollback') . ' failed: ' . $e->getMessage(), '', $e);
        }

        foreach ($commit ? $this->onCommit : $this->onRollback as $callback) {
            $callback();
        }

        foreach ($this->onClose as $onClose) {
            $onClose();
        }
    }

    private function assertActive(): void
    {
        if (!$this->active) {
            throw new SqlTransactionError('The transaction has already been committed or rolled back');
        }
    }
}
