<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Amp\Postgres\PostgresLink;
use Amp\Postgres\PostgresResult;
use Amp\Postgres\PostgresStatement;
use Amp\Postgres\PostgresTransaction;
use Amp\Sql\SqlConnectionException;
use Amp\Sql\SqlException;
use Amp\Sql\SqlQueryError;
use Closure;
use PDO;
use PDOException;
use PDOStatement;

/**
 * A blocking Postgres client over PDO, presenting the same PostgresLink
 * interface as the async drivers — the boot-and-die fallback, same
 * rationale as {@see PdoMysqlClient}.
 */
final class PdoPgsqlClient implements PostgresLink
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
        private readonly int $port = 5432,
    ) {
        $this->lastUsedAt = \time();
    }

    public function query(string $sql): PostgresResult
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

    public function execute(string $sql, array $params = []): PostgresResult
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

    public function prepare(string $sql): PostgresStatement
    {
        throw new SqlException('PdoPgsqlClient does not expose amphp statement objects — use execute()');
    }

    public function beginTransaction(): PostgresTransaction
    {
        try {
            $this->connection()->beginTransaction();
        } catch (PDOException $e) {
            throw new SqlQueryError('Failed to begin transaction: ' . $e->getMessage(), '', $e);
        }

        return new PdoPgsqlTransaction($this, $this->connection());
    }

    public function notify(string $channel, string $payload = ''): PostgresResult
    {
        $sql = 'NOTIFY ' . $this->quoteIdentifier($channel)
            . ($payload === '' ? '' : ', ' . $this->quoteLiteral($payload));

        return $this->query($sql);
    }

    public function quoteLiteral(string $data): string
    {
        return $this->connection()->quote($data);
    }

    public function quoteIdentifier(string $name): string
    {
        return '"' . \str_replace('"', '""', $name) . '"';
    }

    public function escapeByteA(string $data): string
    {
        return '\\x' . \bin2hex($data);
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

    /** @internal Used by {@see PdoPgsqlTransaction}. */
    public function buildResult(PDOStatement $statement): BufferedPostgresResult
    {
        $this->lastUsedAt = \time();

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->columnCount() > 0 ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];

        return new BufferedPostgresResult(
            $rows,
            $statement->columnCount() > 0 ? \count($rows) : $statement->rowCount(),
            $statement->columnCount() > 0 ? $statement->columnCount() : null,
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
                "pgsql:host={$this->host};port={$this->port};dbname={$this->database}",
                $this->user,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                ],
            );
        } catch (PDOException $e) {
            throw new SqlConnectionException('Failed to connect to Postgres: ' . $e->getMessage(), 0, $e);
        }
    }
}
