<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Amp\Postgres\PostgresResult;
use Amp\Postgres\PostgresStatement;
use Amp\Postgres\PostgresTransaction;
use Amp\Sql\SqlException;
use Amp\Sql\SqlTransactionError;
use Amp\Sql\SqlTransactionIsolation;
use Amp\Sql\SqlTransactionIsolationLevel;
use Closure;
use Throwable;

/**
 * A transaction on {@see PgsqlAsyncClient}: pins one connection (BEGIN
 * already ran on it), routes query()/execute() there, and hands the
 * connection back on commit/rollback/close. Same shape and limitations
 * as {@see MysqliAsyncTransaction}.
 */
final class PgsqlAsyncTransaction implements PostgresTransaction
{
    private bool $active = true;

    /** @var list<Closure(): void> */
    private array $onCommit = [];

    /** @var list<Closure(): void> */
    private array $onRollback = [];

    /** @var list<Closure(): void> */
    private array $onClose = [];

    /**
     * @param Closure(PgsqlAsyncConnection): void $releaseConnection
     */
    public function __construct(
        private readonly PgsqlAsyncClient $client,
        private readonly PgsqlAsyncConnection $connection,
        private readonly Closure $releaseConnection,
    ) {}

    public function query(string $sql): PostgresResult
    {
        $this->assertActive();

        return $this->client->queryOn($this->connection, $sql);
    }

    public function execute(string $sql, array $params = []): PostgresResult
    {
        $this->assertActive();

        return $this->client->executeOn($this->connection, $sql, $params);
    }

    public function prepare(string $sql): PostgresStatement
    {
        throw new SqlException('PgsqlAsyncTransaction does not support prepare() — see PgsqlAsyncClient::prepare()');
    }

    public function beginTransaction(): PostgresTransaction
    {
        throw new SqlTransactionError('Nested transactions are not supported by the native pgsql driver');
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
