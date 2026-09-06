<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Fixtures;

use Closure;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\MysqlTransaction;
use Kinetis\Persistence\Driver\BufferedSqlResult;
use Kinetis\Persistence\Driver\PdoExecutionTrait;
use Kinetis\Persistence\Driver\PdoMysqlTransaction;
use Kinetis\Persistence\Driver\PdoStatementCache;
use Kinetis\Persistence\Driver\PdoTransaction;
use Kinetis\Persistence\Exception\ConnectionException;
use PDO;
use PDOStatement;

/**
 * A client running {@see PdoExecutionTrait} — the shared body both PDO
 * clients are — against an in-memory SQLite connection, so the rule the
 * trait owns can be proven without a MySQL or Postgres server: while a
 * transaction holds the one connection, the root link refuses every
 * statement, and it works again once that transaction ends.
 *
 * SQLite is the connection, not the subject. PDO's own transaction state
 * (beginTransaction/commit/rollBack/inTransaction) is what the trait and
 * {@see PdoTransaction} read, and it behaves the same on every PDO
 * driver.
 */
final class FakePdoClient implements MysqlLink
{
    use PdoExecutionTrait;

    public function __construct(private readonly PDO $handle) {}

    #[\Override]
    public function beginTransaction(): MysqlTransaction
    {
        /** @var PdoMysqlTransaction */
        return $this->startPdoTransaction(
            fn (PDO $pdo, PdoStatementCache $statements, Closure $endOwnership): PdoTransaction
                => new PdoMysqlTransaction($pdo, $statements, $this->buildResult(...), $endOwnership),
        );
    }

    #[\Override]
    public function buildResult(PDOStatement $statement): BufferedSqlResult
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->columnCount() > 0 ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];

        return new BufferedSqlResult(
            $rows,
            $statement->columnCount() > 0 ? \count($rows) : $statement->rowCount(),
            $statement->columnCount() > 0 ? $statement->columnCount() : null,
        );
    }

    #[\Override]
    private function connection(): PDO
    {
        if ($this->closed) {
            throw new ConnectionException('The client has been closed');
        }

        return $this->handle;
    }
}
