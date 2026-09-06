<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Closure;
use Kinetis\Persistence\Contract\MysqlTransaction;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Exception\QueryException;
use mysqli;

/**
 * A transaction on {@see MysqliAsyncClient}: pins one connection from the
 * client's pool for its whole lifetime (START TRANSACTION already ran on
 * it), routes query()/execute() to that connection, and hands the
 * connection back when it ends.
 */
final class MysqliAsyncTransaction extends AbstractTransaction implements MysqlTransaction
{
    /**
     * @param Closure(mysqli, bool): void $releaseConnection Hands the
     *     connection back to the client; the flag discards it instead of
     *     returning it to the idle pool.
     */
    public function __construct(
        private readonly MysqliAsyncClient $client,
        private readonly mysqli $connection,
        private readonly Closure $releaseConnection,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function run(string $sql): SqlResult
    {
        return $this->client->queryOn($this->connection, $sql);
    }

    #[\Override]
    protected function runWithParams(PreflightedQuery $query): SqlResult
    {
        return $this->client->executeOn($this->connection, $query);
    }

    #[\Override]
    protected function finish(bool $commit): void
    {
        $this->client->queryOn($this->connection, $commit ? 'COMMIT' : 'ROLLBACK');
    }

    #[\Override]
    protected function release(bool $discard): void
    {
        ($this->releaseConnection)($this->connection, $discard);
    }

    /**
     * mysqli exposes no transaction-status accessor, so an implicit
     * commit — which on MySQL any DDL statement causes — cannot be seen
     * here at all; keep DDL and raw transaction control out of a
     * transaction on this driver. What the server does report is the
     * error number on a statement it rolled the transaction back for,
     * which is {@see isTerminalFailure()}'s business.
     *
     * A closed client has closed this connection with it, and mysqli
     * answers nothing at all about a closed handle.
     */
    #[\Override]
    protected function stillOnConnection(): bool
    {
        return !$this->client->isClosed();
    }

    #[\Override]
    protected function isTerminalFailure(QueryException $failure): bool
    {
        return MysqlLockFailure::isTerminal($failure);
    }

    #[\Override]
    protected function driverLabel(): string
    {
        return 'the native mysqli driver';
    }
}
