<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Fixtures;

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\MysqlTransaction;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Driver\BufferedSqlResult;
use Kinetis\Persistence\Driver\FiberTransactions;
use stdClass;

/**
 * A pooled client with the ownership shape both native drivers have and
 * nothing else: {@see FiberTransactions} deciding whether the root link
 * serves the calling Fiber, and a connection pinned by a transaction
 * until that transaction hands it back or discards it.
 *
 * MysqliAsyncClient and PgsqlAsyncClient need a reachable server to
 * exist at all, and what a transaction holds of either beyond the live
 * connection is exactly this bookkeeping — an owner entry and a pinned
 * connection, both of which every way out of a transaction has to give
 * up.
 *
 * @internal
 */
final class FakePooledClient implements MysqlLink
{
    /** @var list<stdClass> Connections a transaction is holding right now. */
    public array $pinned = [];

    /** @var list<stdClass> Connections handed back to the pool. */
    public array $idle = [];

    /** @var list<bool> One entry per released connection, true when it was discarded. */
    public array $released = [];

    /** @var list<bool> One entry per COMMIT or ROLLBACK a transaction sent, true for COMMIT. */
    public array $finished = [];

    /** @var list<string> Statements a transaction ran on its pinned connection. */
    public array $onConnection = [];

    /** @var list<string> Statements the root link served. */
    public array $served = [];

    private readonly FiberTransactions $transactions;

    public function __construct()
    {
        $this->transactions = new FiberTransactions();
    }

    #[\Override]
    public function query(string $sql): SqlResult
    {
        $this->transactions->assertNone();
        $this->served[] = $sql;

        return new BufferedSqlResult([], 0, null);
    }

    #[\Override]
    public function execute(string $sql, array $params = []): SqlResult
    {
        return $this->query($sql);
    }

    #[\Override]
    public function beginTransaction(): MysqlTransaction
    {
        $connection = new stdClass();
        $this->pinned[] = $connection;
        $owner = $this->transactions->open();

        $release = function (stdClass $connection, bool $discard) use ($owner): void {
            $this->transactions->close($owner);
            $this->pinned = \array_values(\array_filter(
                $this->pinned,
                static fn (stdClass $held): bool => $held !== $connection,
            ));
            $this->released[] = $discard;

            if (!$discard) {
                $this->idle[] = $connection;
            }
        };

        return new FakePooledTransaction($this, $connection, $release);
    }

    #[\Override]
    public function close(): void
    {
        $this->pinned = [];
        $this->idle = [];
    }

    #[\Override]
    public function isClosed(): bool
    {
        return false;
    }
}
