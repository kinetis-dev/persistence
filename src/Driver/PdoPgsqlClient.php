<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Kinetis\Persistence\ConnectionOptions;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Contract\PostgresTransaction;
use Kinetis\Persistence\Exception\ConnectionException;
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
 *
 * The single lazily-opened connection lives for the client's lifetime
 * and is never reopened — matching the boot-and-die FPM model this
 * driver targets, where the process (and client) die with the request.
 * A long-lived process needing reconnection should run the {@see PgsqlAsyncClient}
 * driver instead, whose pool discards and replaces dead connections.
 */
final class PdoPgsqlClient implements PostgresLink
{
    use PdoExecutionTrait;

    private readonly ConnectionOptions $options;

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

    #[\Override]
    public function beginTransaction(): PostgresTransaction
    {
        return new PdoPgsqlTransaction($this->beginPdoTransaction(), $this->buildResult(...));
    }

    /** @internal Also used by {@see PdoPgsqlTransaction} via closure. */
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

    /**
     * Called only from {@see PdoExecutionTrait} (via its own
     * `abstract private function connection(): PDO;`), never directly
     * from this class's own body — static analysis that doesn't resolve
     * trait method calls across the trait boundary will see this as
     * unused; it isn't.
     */
    #[\Override]
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
