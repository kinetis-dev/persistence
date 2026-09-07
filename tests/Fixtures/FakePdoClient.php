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
use PDO;
use PDOStatement;

/**
 * A client running {@see PdoExecutionTrait} — the shared body both PDO
 * clients are — against in-memory SQLite connections, so the rules the
 * trait owns can be proven without a MySQL or Postgres server: while a
 * transaction holds the session, the root link refuses every statement;
 * a discarded session is replaced on the next call, or closes the client
 * where $singleSession forbids a replacement; and `close()` is final.
 *
 * SQLite is the connection, not the subject. PDO's own transaction state
 * (beginTransaction/commit/rollBack/inTransaction) is what the trait and
 * {@see PdoTransaction} read, and it behaves the same on every PDO
 * driver.
 *
 * $open stands in for the real clients' DSN and attributes: it is called
 * once per session, so a replacement session is a distinct PDO handle
 * over a distinct database — which is also what "the work went with the
 * discarded session" looks like here.
 */
final class FakePdoClient implements MysqlLink
{
    use PdoExecutionTrait;

    /** @param Closure(): PDO $open */
    public function __construct(private readonly Closure $open, bool $singleSession = false)
    {
        $this->singleSession = $singleSession;
    }

    /**
     * A client over a fresh in-memory database per session, each with
     * the `items` table the ownership tests write to.
     */
    public static function overSqlite(bool $singleSession = false): self
    {
        return new self(static function (): PDO {
            $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');

            return $pdo;
        }, $singleSession);
    }

    /** The handle this client is on right now, opening one if it has none. */
    public function session(): PDO
    {
        return $this->connection();
    }

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
    private function openConnection(): PDO
    {
        return ($this->open)();
    }
}
