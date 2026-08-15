<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

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
     * Prepared statements memoized per SQL string for this connection's
     * lifetime. Both PDO clients run with native (non-emulated)
     * prepares, where every prepare() is its own server round trip —
     * without reuse, execute() pays two round trips per query and a
     * hot loop issuing the same statement N times costs 2N instead of
     * N+1. MySQL and Postgres both scope prepared statements to the
     * connection, which is exactly this cache's lifetime; close()
     * drops it with the connection.
     *
     * @var array<string, PDOStatement>
     */
    private array $statements = [];

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
            $statement = $this->statements[$sql] ?? null;

            if ($statement === null) {
                // An unbounded cache would let a workload that
                // interpolates values into its SQL (instead of binding)
                // grow it without limit; a full reset is crude but keeps
                // the steady state — a bounded set of parameterized
                // statements — at exactly one prepare each.
                if (\count($this->statements) >= 256) {
                    $this->statements = [];
                }

                $prepared = $this->connection()->prepare($sql);

                if ($prepared === false) {
                    throw new QueryException('Failed to prepare query', $sql);
                }

                $statement = $this->statements[$sql] = $prepared;
            }

            PdoParamBinder::bind($statement, $params);
            $statement->execute();
        } catch (PDOException $e) {
            throw new QueryException($e->getMessage(), $sql, $e);
        }

        return $this->buildResult($statement);
    }

    public function close(): void
    {
        $this->closed = true;
        $this->statements = [];
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

    /** Opens (or returns) the one lazily-created PDO connection. */
    abstract private function connection(): PDO;

    /** Builds the buffered result — dialects differ on lastInsertId. */
    abstract public function buildResult(PDOStatement $statement): BufferedSqlResult;
}
