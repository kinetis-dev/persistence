<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Kinetis\Persistence\ConnectionOptions;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Contract\PostgresTransaction;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Exception\ConnectionException;
use Kinetis\Persistence\Exception\QueryException;
use PDO;
use PDOException;
use PDOStatement;

/**
 * A blocking Postgres client over PDO, presenting the same PostgresLink
 * contract as the async driver — the boot-and-die fallback, same
 * rationale as {@see PdoMysqlClient}.
 *
 * PDO's pgsql DSN is handed to libpq as a connection string, so the
 * canonical options (and the free-form extra string) translate directly
 * to libpq keys.
 */
final class PdoPgsqlClient implements PostgresLink
{
    private readonly ConnectionOptions $options;

    private ?PDO $pdo = null;

    private bool $closed = false;

    public function __construct(
        private readonly string $host,
        private readonly string $user,
        #[\SensitiveParameter] private readonly string $password,
        private readonly string $database,
        private readonly int $port = 5432,
        ?ConnectionOptions $options = null,
    ) {
        $this->options = $options ?? new ConnectionOptions();
        // Collation and protocol compression are MySQL concepts.
        $this->options->rejectUnsupported('PDO pgsql', ['collation', 'compression']);
    }

    public function query(string $sql): SqlResult
    {
        try {
            $statement = $this->connection()->query($sql);

            if ($statement === false) {
                throw new QueryException('Query failed', $sql);
            }
        } catch (PDOException $e) {
            throw new QueryException($e->getMessage(), $sql, $e);
        }

        return $this->buildResult($statement);
    }

    public function execute(string $sql, array $params = []): SqlResult
    {
        try {
            $statement = $this->connection()->prepare($sql);

            if ($statement === false) {
                throw new QueryException('Failed to prepare query', $sql);
            }

            $statement->execute(\array_values($params));
        } catch (PDOException $e) {
            throw new QueryException($e->getMessage(), $sql, $e);
        }

        return $this->buildResult($statement);
    }

    public function beginTransaction(): PostgresTransaction
    {
        try {
            $this->connection()->beginTransaction();
        } catch (PDOException $e) {
            throw new QueryException('Failed to begin transaction: ' . $e->getMessage(), '', $e);
        }

        return new PdoPgsqlTransaction($this, $this->connection());
    }

    public function close(): void
    {
        $this->closed = true;
        $this->pdo = null;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** @internal Used by {@see PdoPgsqlTransaction}. */
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

    private function connection(): PDO
    {
        if ($this->closed) {
            throw new ConnectionException('The client has been closed');
        }

        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $dsn = "pgsql:host={$this->host};port={$this->port};dbname={$this->database}";

        if ($this->options->charset !== null) {
            $dsn .= ";client_encoding={$this->options->charset}";
        }

        if ($this->options->sslMode !== null) {
            $dsn .= ";sslmode={$this->options->sslMode}";
        }

        if ($this->options->connectTimeout !== null) {
            $dsn .= ";connect_timeout={$this->options->connectTimeout}";
        }

        if ($this->options->applicationName !== null) {
            $dsn .= ";application_name={$this->options->applicationName}";
        }

        if ($this->options->extraConnectionString !== '') {
            // Space-separated libpq pairs become semicolon-separated DSN
            // pairs; libpq validates them and fails the connect loudly.
            $dsn .= ';' . \str_replace(' ', ';', $this->options->extraConnectionString);
        }

        try {
            return $this->pdo = new PDO($dsn, $this->user, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        } catch (PDOException $e) {
            throw new ConnectionException('Failed to connect to Postgres: ' . $e->getMessage(), 0, $e);
        }
    }
}
