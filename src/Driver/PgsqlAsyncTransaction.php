<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Closure;
use Kinetis\Persistence\Contract\PostgresTransaction;
use Kinetis\Persistence\Contract\SqlResult;

/**
 * A transaction on {@see PgsqlAsyncClient}: pins one connection (BEGIN
 * already ran on it), routes query()/execute() there, and hands the
 * connection back on commit/rollback/close.
 */
final class PgsqlAsyncTransaction extends AbstractTransaction implements PostgresTransaction
{
    /**
     * @param Closure(PgsqlAsyncConnection): void $releaseConnection
     */
    public function __construct(
        private readonly PgsqlAsyncClient $client,
        private readonly PgsqlAsyncConnection $connection,
        private readonly Closure $releaseConnection,
    ) {}

    protected function run(string $sql): SqlResult
    {
        return $this->client->queryOn($this->connection, $sql);
    }

    protected function runWithParams(string $sql, array $params): SqlResult
    {
        return $this->client->executeOn($this->connection, $sql, $params);
    }

    protected function finish(bool $commit): void
    {
        try {
            $this->client->queryOn($this->connection, $commit ? 'COMMIT' : 'ROLLBACK');
        } finally {
            ($this->releaseConnection)($this->connection);
        }
    }

    protected function driverLabel(): string
    {
        return 'the native pgsql driver';
    }
}
