<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Kinetis\Instrumentation\Telemetry;
use Kinetis\Persistence\Contract\MysqlLink;
use Throwable;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Exception\QueryException;
use PDO;
use PDOException;
use PDOStatement;

/**
 * The execution body both PDO clients share — everything except how the
 * connection is opened (DSN/attributes) and how a result is built
 * (dialects differ on lastInsertId), which stay with the client.
 *
 * @internal
 *
 * @phpstan-require-implements \Kinetis\Persistence\Contract\SqlLink
 */
trait PdoExecutionTrait
{
    private ?PDO $pdo = null;

    private bool $closed = false;

    /**
     * The pre-flight every execute() passes before this client does
     * anything at all, and the statements memoized per SQL string for
     * this connection's lifetime. Both are built on first use rather
     * than in a constructor, which a trait has none of, and dropped by
     * close() with the connection.
     */
    private ?SqlParamPreflight $preflight = null;

    private ?PdoStatementCache $statements = null;

    /**
     * Opens the connection now instead of on first use. A PDO client is
     * a single connection, so $connections beyond 1 changes nothing —
     * the parameter exists so every driver shares one warmUp()
     * signature and callers never branch on driver type.
     *
     * Throws on an unreachable server — a warmed connection is an
     * explicit request, so failing to open it is an error, not a
     * silent fall-back to lazy connecting.
     */
    public function warmUp(?int $connections = null): void
    {
        $this->connection();
    }

    public function query(string $sql): SqlResult
    {
        $telemetry = Telemetry::global();
        $token = $telemetry->queryDispatched($this instanceof MysqlLink ? 'mysql' : 'postgresql', $sql);
        // A single blocking connection: dispatch and server start are the
        // same moment here.
        $telemetry->queryServerStarted($token);

        try {
            $statement = $this->connection()->query($sql);

            if ($statement === false) {
                throw new QueryException('Query failed', $sql);
            }
        } catch (PDOException $e) {
            $failure = new QueryException($e->getMessage(), $sql, $e);
            $telemetry->queryReaped($token, $failure);

            throw $failure;
        } catch (Throwable $e) {
            $telemetry->queryReaped($token, $e);

            throw $e;
        }

        $telemetry->queryReaped($token, null);

        return $this->buildResult($statement);
    }

    public function execute(string $sql, array $params = []): SqlResult
    {
        // Ahead of the span, connection() and the statement memo —
        // {@see SqlParamPreflight} for why that ordering is the contract.
        $this->preflight ??= new SqlParamPreflight($this->dialect());
        $query = $this->preflight->run($sql, $params);

        $telemetry = Telemetry::global();
        $token = $telemetry->queryDispatched($this instanceof MysqlLink ? 'mysql' : 'postgresql', $sql);
        $telemetry->queryServerStarted($token);

        try {
            $this->statements ??= new PdoStatementCache();
            $statement = $this->statements->execute($this->connection(), $query);
        } catch (PDOException $e) {
            $failure = new QueryException($e->getMessage(), $sql, $e);
            $telemetry->queryReaped($token, $failure);

            throw $failure;
        } catch (Throwable $e) {
            $telemetry->queryReaped($token, $e);

            throw $e;
        }

        $telemetry->queryReaped($token, null);

        return $this->buildResult($statement);
    }

    public function close(): void
    {
        $this->closed = true;
        $this->preflight = null;
        $this->statements = null;
        $this->pdo = null;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** Starts PDO's native transaction on the lazily-opened connection. */
    private function beginPdoTransaction(): PDO
    {
        try {
            $this->connection()->beginTransaction();
        } catch (PDOException $e) {
            throw new QueryException('Failed to begin transaction: ' . $e->getMessage(), '', $e);
        }

        return $this->connection();
    }

    /** Which lexical rules this client's pre-flight scans SQL under. */
    private function dialect(): SqlDialect
    {
        return $this instanceof MysqlLink ? SqlDialect::Mysql : SqlDialect::Postgres;
    }

    /** Opens (or returns) the one lazily-created PDO connection. */
    abstract private function connection(): PDO;

    /** Builds the buffered result — dialects differ on lastInsertId. */
    abstract public function buildResult(PDOStatement $statement): BufferedSqlResult;
}
