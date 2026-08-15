<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Kinetis\Persistence\ConnectionOptions;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\MysqlTransaction;
use Kinetis\Persistence\Exception\ConnectionException;
use PDO;
use PDOException;
use PDOStatement;

/**
 * A blocking MySQL client over PDO, presenting the same MysqlLink
 * contract as the async driver — the boot-and-die fallback.
 *
 * Under PHP-FPM a worker serves one request at a time and every request
 * pays a fresh connection, so an async client buys nothing there while
 * costing more CPU per query. This client is one lazily-opened PDO
 * connection doing native-speed blocking work; `concurrently()` fan-outs
 * still produce correct results — the queries simply run sequentially,
 * which for sub-millisecond queries is the faster trade.
 *
 * The single lazily-opened connection lives for the client's lifetime
 * and is never reopened — matching the boot-and-die FPM model this
 * driver targets, where the process (and client) die with the request.
 * A long-lived process needing reconnection should run the {@see MysqliAsyncClient}
 * driver instead, whose pool discards and replaces dead connections.
 */
final class PdoMysqlClient implements MysqlLink
{
    use PdoExecutionTrait;

    private readonly ConnectionOptions $options;

    public function __construct(
        private readonly string $host,
        private readonly string $user,
        #[\SensitiveParameter] private readonly string $password,
        private readonly string $database,
        private readonly int $port = 3306,
        ?ConnectionOptions $options = null,
    ) {
        $this->options = $options ?? new ConnectionOptions();
        // MySQL TLS certificate plumbing is not modeled yet;
        // applicationName is a Postgres concept; free-form
        // connection-string text has no PDO-MySQL equivalent.
        $this->options->rejectUnsupported('PDO mysql', ['sslMode', 'applicationName', 'extraConnectionString']);
    }

    public function beginTransaction(): MysqlTransaction
    {
        return new PdoMysqlTransaction($this->beginPdoTransaction(), $this->buildResult(...));
    }

    /** @internal Also used by {@see PdoMysqlTransaction} via closure. */
    public function buildResult(PDOStatement $statement): BufferedSqlResult
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->columnCount() > 0 ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
        $lastInsertId = (int) ($this->connection()->lastInsertId() ?: 0);

        return new BufferedSqlResult(
            $rows,
            $statement->columnCount() > 0 ? \count($rows) : $statement->rowCount(),
            $statement->columnCount() > 0 ? $statement->columnCount() : null,
            $lastInsertId !== 0 ? $lastInsertId : null,
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

        $charset = $this->options->charset ?? 'utf8mb4';
        $attributes = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];

        if ($this->options->connectTimeout !== null) {
            $attributes[PDO::ATTR_TIMEOUT] = $this->options->connectTimeout;
        }

        if ($this->options->compression === true) {
            $attributes[PDO::MYSQL_ATTR_COMPRESS] = true;
        }

        try {
            $pdo = new PDO(
                "mysql:host={$this->host};port={$this->port};dbname={$this->database};charset={$charset}",
                $this->user,
                $this->password,
                $attributes,
            );

            if ($this->options->collation !== null) {
                // Both values are constrained to identifier characters by
                // ConnectionOptions' constructor.
                $pdo->exec(\sprintf("SET NAMES '%s' COLLATE '%s'", $charset, $this->options->collation));
            }
        } catch (PDOException $e) {
            throw new ConnectionException('Failed to connect to MySQL: ' . $e->getMessage(), 0, $e);
        }

        return $this->pdo = $pdo;
    }
}
