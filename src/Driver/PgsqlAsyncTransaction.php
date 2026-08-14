<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Closure;
use Kinetis\Persistence\Contract\PostgresTransaction;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Contract\SqlTransaction;
use Kinetis\Persistence\Exception\TransactionException;

/**
 * A transaction on {@see PgsqlAsyncClient}: pins one connection (BEGIN
 * already ran on it), routes query()/execute() there, and hands the
 * connection back on commit/rollback/close. Nested transactions are not
 * supported.
 */
final class PgsqlAsyncTransaction implements PostgresTransaction
{
    private bool $active = true;

    /**
     * @param Closure(PgsqlAsyncConnection): void $releaseConnection
     */
    public function __construct(
        private readonly PgsqlAsyncClient $client,
        private readonly PgsqlAsyncConnection $connection,
        private readonly Closure $releaseConnection,
    ) {}

    public function query(string $sql): SqlResult
    {
        $this->assertActive();

        return $this->client->queryOn($this->connection, $sql);
    }

    public function execute(string $sql, array $params = []): SqlResult
    {
        $this->assertActive();

        return $this->client->executeOn($this->connection, $sql, $params);
    }

    public function beginTransaction(): SqlTransaction
    {
        throw new TransactionException('Nested transactions are not supported by the native pgsql driver');
    }

    public function commit(): void
    {
        $this->finish('COMMIT');
    }

    public function rollback(): void
    {
        $this->finish('ROLLBACK');
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

    private function finish(string $sql): void
    {
        $this->assertActive();
        $this->active = false;

        try {
            $this->client->queryOn($this->connection, $sql);
        } finally {
            ($this->releaseConnection)($this->connection);
        }
    }

    private function assertActive(): void
    {
        if (!$this->active) {
            throw new TransactionException('The transaction has already been committed or rolled back');
        }
    }
}
