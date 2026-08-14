<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Amp\Mysql\MysqlResult;
use Amp\Mysql\MysqlStatement;
use Amp\Mysql\MysqlTransaction;
use Amp\Sql\SqlConnectionException;
use Amp\Sql\SqlException;
use Amp\Sql\SqlQueryError;
use Closure;
use PDO;
use PDOException;
use PDOStatement;

/**
 * A blocking MySQL client over PDO, presenting the same MysqlLink
 * interface as the async drivers — the boot-and-die fallback.
 *
 * Under PHP-FPM a worker serves one request at a time and every request
 * pays a fresh connection, so an async client buys nothing there while
 * costing far more CPU per query and per handshake (measured 5.5x; see
 * SqlConnectionFactory::fromConfig()'s driver selection). This client is
 * one PDO connection doing native-speed blocking work. Queries issued
 * through `concurrently()` simply run sequentially — same results, no
 * overlap, which for sub-millisecond queries is the faster trade.
 */
final class PdoMysqlClient implements \Amp\Mysql\MysqlLink
{
    private ?PDO $pdo = null;

    private bool $closed = false;

    /** @var list<Closure(): void> */
    private array $onClose = [];

    private int $lastUsedAt;

    public function __construct(
        private readonly string $host,
        private readonly string $user,
        #[\SensitiveParameter] private readonly string $password,
        private readonly string $database,
        private readonly int $port = 3306,
    ) {
        $this->lastUsedAt = \time();
    }

    public function query(string $sql): MysqlResult
    {
        try {
            $statement = $this->connection()->query($sql);

            if ($statement === false) {
                throw new SqlQueryError('Query failed', $sql);
            }
        } catch (PDOException $e) {
            throw new SqlQueryError($e->getMessage(), $sql, $e);
        }

        return $this->buildResult($statement);
    }

    public function execute(string $sql, array $params = []): MysqlResult
    {
        try {
            $statement = $this->connection()->prepare($sql);

            if ($statement === false) {
                throw new SqlQueryError('Failed to prepare query', $sql);
            }

            $statement->execute(\array_values($params));
        } catch (PDOException $e) {
            throw new SqlQueryError($e->getMessage(), $sql, $e);
        }

        return $this->buildResult($statement);
    }

    public function prepare(string $sql): MysqlStatement
    {
        throw new SqlException('PdoMysqlClient does not expose amphp statement objects — use execute()');
    }

    public function beginTransaction(): MysqlTransaction
    {
        try {
            $this->connection()->beginTransaction();
        } catch (PDOException $e) {
            throw new SqlQueryError('Failed to begin transaction: ' . $e->getMessage(), '', $e);
        }

        return new PdoMysqlTransaction($this, $this->connection());
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->pdo = null;

        foreach ($this->onClose as $onClose) {
            $onClose();
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function onClose(Closure $onClose): void
    {
        if ($this->closed) {
            $onClose();

            return;
        }

        $this->onClose[] = $onClose;
    }

    public function getLastUsedAt(): int
    {
        return $this->lastUsedAt;
    }

    /** @internal Used by {@see PdoMysqlTransaction}. */
    public function buildResult(PDOStatement $statement): BufferedMysqlResult
    {
        $this->lastUsedAt = \time();

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->columnCount() > 0 ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
        $lastInsertId = (int) ($this->connection()->lastInsertId() ?: 0);

        return new BufferedMysqlResult(
            $rows,
            $statement->columnCount() > 0 ? \count($rows) : $statement->rowCount(),
            $statement->columnCount() > 0 ? $statement->columnCount() : null,
            $lastInsertId !== 0 ? $lastInsertId : null,
        );
    }

    private function connection(): PDO
    {
        if ($this->closed) {
            throw new SqlConnectionException('The client has been closed');
        }

        if ($this->pdo !== null) {
            return $this->pdo;
        }

        try {
            return $this->pdo = new PDO(
                "mysql:host={$this->host};port={$this->port};dbname={$this->database};charset=utf8mb4",
                $this->user,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                ],
            );
        } catch (PDOException $e) {
            throw new SqlConnectionException('Failed to connect to MySQL: ' . $e->getMessage(), 0, $e);
        }
    }
}
