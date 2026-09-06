<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Closure;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Exception\ConnectionException;
use Kinetis\Persistence\Exception\QueryException;
use Kinetis\Persistence\Exception\TransactionException;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Shared body for the PDO transactions — PDO is a single connection, so
 * a transaction routes through the same handle with PDO's own native
 * transaction state. Dialect finals only tag the marker interface.
 *
 * PDO::inTransaction() reads the transaction status the server sent with
 * the last packet, so it is a local read that still answers for the
 * server: it is what makes MySQL's implicit DDL commit, and the rollback
 * the server does to the loser of a deadlock, visible here rather than
 * silently turning the rest of the transaction into autocommit
 * statements.
 *
 * Ending releases every reference this object holds to the physical
 * session: the handle, the client's statement memo, and the closures
 * bound to the client. A PDOStatement keeps its connection open as
 * surely as the handle does, so a completed transaction someone kept a
 * variable pointing at would otherwise hold the session — and its locks
 * — for as long as that variable lives.
 *
 * @internal
 */
abstract class PdoTransaction extends AbstractTransaction
{
    private const string RELEASED_MESSAGE = 'The connection this transaction ran on has been released';

    private ?PDO $pdo;

    private ?PdoStatementCache $statements;

    /** @var (Closure(PDOStatement): SqlResult)|null */
    private ?Closure $buildResult;

    /** @var (Closure(bool): void)|null */
    private ?Closure $endOwnership;

    /**
     * Each of these is held only while the transaction is open, which is
     * why the properties are nullable and the parameters are not.
     *
     * @param PdoStatementCache $statements The owning client's memo for
     *     this same connection.
     * @param Closure(PDOStatement): SqlResult $buildResult The owning
     *     client's result construction (dialects differ on lastInsertId).
     * @param Closure(bool): void $endOwnership Releases the client's
     *     connection back to its root link; the flag discards it.
     */
    public function __construct(
        PDO $pdo,
        PdoStatementCache $statements,
        Closure $buildResult,
        Closure $endOwnership,
    ) {
        $this->pdo = $pdo;
        $this->statements = $statements;
        $this->buildResult = $buildResult;
        $this->endOwnership = $endOwnership;

        parent::__construct();
    }

    #[\Override]
    protected function run(string $sql): SqlResult
    {
        // Building the result is inside the boundary, not after it: a
        // later result set can carry the server's own error, and it
        // reaches the caller as this package's exception rather than
        // PDO's.
        try {
            $statement = $this->handle()->query($sql);

            if ($statement === false) {
                throw new QueryException('Query failed', $sql);
            }

            return $this->build($statement);
        } catch (PDOException $e) {
            throw new QueryException($e->getMessage(), $sql, $e, PdoError::vendorCode($e));
        }
    }

    #[\Override]
    protected function runWithParams(PreflightedQuery $query): SqlResult
    {
        try {
            $statement = $this->statements()->execute($this->handle(), $query);

            return $this->build($statement);
        } catch (PDOException $e) {
            throw new QueryException($e->getMessage(), $query->sql, $e, PdoError::vendorCode($e));
        }
    }

    #[\Override]
    protected function finish(bool $commit): void
    {
        try {
            $commit ? $this->handle()->commit() : $this->handle()->rollBack();
        } catch (PDOException $e) {
            throw new TransactionException(($commit ? 'Commit' : 'Rollback') . ' failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Whatever the client does with the connection next, this object
     * stops holding it here — on every terminal transition, discard or
     * not. The client's memo is only dereferenced: on an ordinary commit
     * or rollback the connection lives on, and the statements prepared
     * on it stay worth reusing.
     */
    #[\Override]
    protected function release(bool $discard): void
    {
        $endOwnership = $this->endOwnership;

        $this->pdo = null;
        $this->statements = null;
        $this->buildResult = null;
        $this->endOwnership = null;

        if ($endOwnership !== null) {
            $endOwnership($discard);
        }
    }

    #[\Override]
    protected function stillOnConnection(): bool
    {
        return $this->pdo?->inTransaction() === true;
    }

    #[\Override]
    protected function driverLabel(): string
    {
        return 'the PDO driver';
    }

    private function build(PDOStatement $statement): SqlResult
    {
        return ($this->buildResult ?? throw new ConnectionException(self::RELEASED_MESSAGE))($statement);
    }

    /** Unreachable once the transaction has ended, which is the only state that releases the handle. */
    private function handle(): PDO
    {
        return $this->pdo ?? throw new ConnectionException(self::RELEASED_MESSAGE);
    }

    private function statements(): PdoStatementCache
    {
        return $this->statements ?? throw new ConnectionException(self::RELEASED_MESSAGE);
    }
}
