<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Closure;
use Kinetis\Persistence\Contract\MysqlTransaction;
use Kinetis\Persistence\Contract\SqlResult;
use mysqli;

/**
 * A transaction on {@see MysqliAsyncClient}: pins one connection from the
 * client's pool for its whole lifetime (START TRANSACTION already ran on
 * it), routes query()/execute() to that connection, and hands the
 * connection back on commit/rollback/close.
 */
final class MysqliAsyncTransaction extends AbstractTransaction implements MysqlTransaction
{
    /**
     * @param Closure(mysqli): void $releaseConnection
     */
    public function __construct(
        private readonly MysqliAsyncClient $client,
        private readonly mysqli $connection,
        private readonly Closure $releaseConnection,
    ) {}

    #[\Override]
    protected function run(string $sql): SqlResult
    {
        return $this->client->queryOn($this->connection, $sql);
    }

    #[\Override]
    protected function runWithParams(string $sql, array $params): SqlResult
    {
        return $this->client->executeOn($this->connection, $sql, $params);
    }

    #[\Override]
    protected function finish(bool $commit): void
    {
        try {
            $this->client->queryOn($this->connection, $commit ? 'COMMIT' : 'ROLLBACK');
        } finally {
            ($this->releaseConnection)($this->connection);
        }
    }

    #[\Override]
    protected function driverLabel(): string
    {
        return 'the native mysqli driver';
    }
}
