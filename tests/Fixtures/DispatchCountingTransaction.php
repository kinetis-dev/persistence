<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Fixtures;

use Kinetis\Persistence\Contract\MysqlTransaction;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Driver\AbstractTransaction;
use Kinetis\Persistence\Driver\BufferedSqlResult;
use Kinetis\Persistence\Driver\PreflightedQuery;

/**
 * An AbstractTransaction that counts how often a subclass was asked to
 * dispatch anything, and owns no connection at all.
 *
 * It stands for the two native transactions in the pre-flight ordering
 * tests. MysqliAsyncTransaction and PgsqlAsyncTransaction pin a live
 * connection handed to them by their client — a mysqli, a
 * PgSql\Connection — which cannot exist without a reachable server, so
 * neither can be constructed to prove offline that a refused argument
 * list never reaches its pinned connection. What can be proven offline
 * is the gate both of them sit behind: AbstractTransaction::execute()
 * settles the whole argument list and only then calls runWithParams(),
 * which is the only route either native transaction has to its
 * connection. The real pair are exercised against real servers by
 * tests/Integration/BindableValueContractTest.
 */
final class DispatchCountingTransaction extends AbstractTransaction implements MysqlTransaction
{
    public int $dispatches = 0;

    /** @var list<null|bool|int|float|string> What the last accepted call carried. */
    public array $lastValues = [];

    public function __construct()
    {
        $this->telemetryBegin();
    }

    #[\Override]
    protected function run(string $sql): SqlResult
    {
        return $this->dispatched();
    }

    #[\Override]
    protected function runWithParams(PreflightedQuery $query): SqlResult
    {
        $this->lastValues = $query->values;

        return $this->dispatched();
    }

    #[\Override]
    protected function finish(bool $commit): void {}

    #[\Override]
    protected function driverLabel(): string
    {
        return 'the dispatch-counting test driver';
    }

    private function dispatched(): SqlResult
    {
        $this->dispatches++;

        return new BufferedSqlResult([], 0, null);
    }
}
