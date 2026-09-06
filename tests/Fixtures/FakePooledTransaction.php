<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Fixtures;

use Closure;
use Kinetis\Persistence\Contract\MysqlTransaction;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Driver\AbstractTransaction;
use Kinetis\Persistence\Driver\BufferedSqlResult;
use Kinetis\Persistence\Driver\PreflightedQuery;
use stdClass;

/**
 * A transaction shaped like the native ones: a client, one connection of
 * its pool pinned for the transaction's whole life, and the release
 * callback that hands that connection back and drops the client's
 * Fiber-ownership entry with it.
 *
 * What it does with a statement or a finish is recorded on the client,
 * not here, since an abandoned transaction is gone by the time a test
 * asks what it did.
 *
 * @internal
 */
final class FakePooledTransaction extends AbstractTransaction implements MysqlTransaction
{
    /** @param Closure(stdClass, bool): void $releaseConnection Hands the connection back; the flag discards it. */
    public function __construct(
        private readonly FakePooledClient $client,
        private readonly stdClass $connection,
        private readonly Closure $releaseConnection,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function run(string $sql): SqlResult
    {
        $this->client->onConnection[] = $sql;

        return new BufferedSqlResult([], 0, null);
    }

    #[\Override]
    protected function runWithParams(PreflightedQuery $query): SqlResult
    {
        return $this->run($query->sql);
    }

    #[\Override]
    protected function finish(bool $commit): void
    {
        $this->client->finished[] = $commit;
    }

    #[\Override]
    protected function release(bool $discard): void
    {
        ($this->releaseConnection)($this->connection, $discard);
    }

    #[\Override]
    protected function driverLabel(): string
    {
        return 'the fake pooled driver';
    }
}
