<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Amp\Mysql\MysqlResult;
use Amp\Mysql\MysqlStatement;
use Amp\Mysql\MysqlTransaction;
use Amp\Sql\SqlException;
use Amp\Sql\SqlTransactionError;
use Amp\Sql\SqlTransactionIsolation;
use Amp\Sql\SqlTransactionIsolationLevel;
use Closure;
use mysqli;
use Throwable;

/**
 * A transaction on {@see MysqliAsyncClient}: pins one connection from the
 * client's pool for its whole lifetime (START TRANSACTION already ran on
 * it), routes query()/execute() to that connection, and hands the
 * connection back on commit/rollback/close.
 *
 * The isolation level reported is the server's default (READ COMMITTED
 * is assumed for reporting purposes); this driver does not set one
 * explicitly. Nested transactions (savepoints) are not implemented.
 */
final class MysqliAsyncTransaction implements MysqlTransaction
{
    private bool $active = true;

    /** @var list<Closure(): void> */
    private array $onCommit = [];

    /** @var list<Closure(): void> */
    private array $onRollback = [];

    /** @var list<Closure(): void> */
    private array $onClose = [];

    /**
     * @param Closure(mysqli): void $releaseConnection
     */
    public function __construct(
        private readonly MysqliAsyncClient $client,
        private readonly mysqli $connection,
        private readonly Closure $releaseConnection,
    ) {}

    public function query(string $sql): MysqlResult
    {
        $this->assertActive();

        return $this->client->queryOn($this->connection, $sql);
    }

    public function execute(string $sql, array $params = []): MysqlResult
    {
        $this->assertActive();

        return $this->client->executeOn($this->connection, $sql, $params);
    }

    public function prepare(string $sql): MysqlStatement
    {
        throw new SqlException('MysqliAsyncTransaction does not support prepare() — see MysqliAsyncClient::prepare()');
    }

    public function beginTransaction(): MysqlTransaction
    {
        throw new SqlTransactionError('Nested transactions are not supported by the native mysqli driver');
    }

    public function commit(): void
    {
        $this->finish('COMMIT', $this->onCommit);
    }

    public function rollback(): void
    {
        $this->finish('ROLLBACK', $this->onRollback);
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

    /**
     * @param list<Closure(): void> $callbacks
     */
    private function finish(string $sql, array $callbacks): void
    {
        $this->assertActive();
        $this->active = false;

        try {
            $this->client->queryOn($this->connection, $sql);
        } catch (Throwable $e) {
            ($this->releaseConnection)($this->connection);

            foreach ($this->onClose as $onClose) {
                $onClose();
            }

            throw $e;
        }

        ($this->releaseConnection)($this->connection);

        foreach ($callbacks as $callback) {
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
