<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Closure;
use Kinetis\Persistence\Contract\PostgresTransaction;
use Kinetis\Persistence\Contract\SqlResult;

/**
 * A transaction on {@see PgsqlAsyncClient}: pins one connection (BEGIN
 * already ran on it), routes query()/execute() there, and hands the
 * connection back when it ends.
 */
final class PgsqlAsyncTransaction extends AbstractTransaction implements PostgresTransaction
{
    /**
     * @param Closure(PgsqlAsyncConnection, bool): void $releaseConnection
     *     Hands the connection back to the client; the flag discards it
     *     instead of returning it to the idle pool.
     */
    public function __construct(
        private readonly PgsqlAsyncClient $client,
        private readonly PgsqlAsyncConnection $connection,
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
     * libpq tracks the transaction status the server sent with the last
     * message, so pg_transaction_status() is a local read that still
     * answers for the server. IDLE means the server is holding no
     * transaction — it ended this one itself — while a failed statement
     * leaves the block open in an error state, which is INERROR and
     * still this transaction's own.
     */
    #[\Override]
    protected function stillOnConnection(): bool
    {
        // libpq answers nothing at all about a closed handle, and both
        // of these mean this connection's is closed: the client took
        // every connection with it, or the pool took this one.
        if ($this->client->isClosed() || $this->connection->broken) {
            return false;
        }

        $status = \pg_transaction_status($this->connection->handle);

        if ($status === \PGSQL_TRANSACTION_UNKNOWN) {
            // The connection itself is no longer usable, so the
            // transaction is over wherever the server is — and the
            // connection must not go back to the pool.
            $this->connection->broken = true;

            return false;
        }

        return $status !== \PGSQL_TRANSACTION_IDLE;
    }

    #[\Override]
    protected function driverLabel(): string
    {
        return 'the native pgsql driver';
    }
}
