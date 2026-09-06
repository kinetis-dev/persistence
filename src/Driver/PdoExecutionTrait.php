<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Closure;
use InvalidArgumentException;
use Kinetis\Instrumentation\Telemetry;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\SqlTransaction;
use Throwable;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Exception\QueryException;
use Kinetis\Persistence\Exception\TransactionException;
use PDO;
use PDOException;
use PDOStatement;
use WeakReference;

/**
 * The execution body both PDO clients share — everything except how the
 * connection is opened (DSN/attributes) and how a result is built
 * (dialects differ on lastInsertId), which stay with the client.
 *
 * A PDO client is one connection, so a transaction owns the whole
 * client while it lasts: the root link refuses query(), execute() and a
 * second beginTransaction() from any Fiber until that transaction ends
 * ({@see assertNoTransaction()}). Running them anyway would enclose one
 * caller's statements in another's transaction, committing or rolling
 * back work that never asked to be part of it. Closing the client ends
 * that transaction first, since it holds the same connection.
 *
 * Ownership is held weakly, so a transaction nobody ended can still be
 * destroyed and give the connection up — the one way out a client
 * holding a single connection has, since the alternative is holding
 * that session and its locks until the client itself goes.
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
     * The transaction currently holding this client's connection, if
     * any — weakly, so the client is never what keeps it alive. A
     * transaction application code began directly and then dropped ends
     * itself when its last reference goes
     * ({@see AbstractTransaction::__destruct()}); a strong reference
     * here would be that last reference, and the client would hold the
     * session, its transaction and its locks for the rest of its own
     * life. Weak rather than absent because the client still has to
     * reach a live transaction: close() ends it, and
     * {@see assertNoTransaction()} asks it whether the server has ended
     * it already.
     *
     * @var WeakReference<SqlTransaction>|null
     */
    private ?WeakReference $owningTransaction = null;

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
        $this->assertNoTransaction();

        return $this->inSpan($sql, function () use ($sql): SqlResult {
            $statement = $this->connection()->query($sql);

            if ($statement === false) {
                throw new QueryException('Query failed', $sql);
            }

            return $this->buildResult($statement);
        });
    }

    public function execute(string $sql, array $params = []): SqlResult
    {
        $this->assertNoTransaction();

        // Ahead of the span, connection() and the statement memo —
        // {@see SqlParamPreflight} for why that ordering is the contract.
        $this->preflight ??= new SqlParamPreflight($this->dialect());
        $query = $this->preflight->run($sql, $params);

        return $this->inSpan($sql, function () use ($query): SqlResult {
            $statement = $this->statementCache()->execute($this->connection(), $query);

            return $this->buildResult($statement);
        });
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        // The transaction holding this client goes first: it runs on the
        // same handle and the same statement memo, and a PDOStatement
        // keeps its connection open as surely as the handle does. It
        // ends whether or not its rollback reaches the server, so the
        // connection is dropped either way.
        $transaction = $this->heldTransaction();
        $this->owningTransaction = null;

        try {
            $transaction?->close();
        } finally {
            $this->dropConnection();
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Runs one statement inside its telemetry span. Building the result
     * is part of it: a later result set can carry the server's own
     * error, so the span records a success only once the whole result
     * exists, and every PDO failure along the way reaches the caller as
     * this package's own exception.
     *
     * @param Closure(): SqlResult $statement
     */
    private function inSpan(string $sql, Closure $statement): SqlResult
    {
        $telemetry = Telemetry::global();
        $token = $telemetry->queryDispatched($this instanceof MysqlLink ? 'mysql' : 'postgresql', $sql);
        // A single blocking connection: dispatch and server start are the
        // same moment here.
        $telemetry->queryServerStarted($token);

        try {
            $result = $statement();
        } catch (PDOException $e) {
            $failure = new QueryException($e->getMessage(), $sql, $e, PdoError::vendorCode($e));
            $telemetry->queryReaped($token, $failure);

            throw $failure;
        } catch (Throwable $e) {
            $telemetry->queryReaped($token, $e);

            throw $e;
        }

        $telemetry->queryReaped($token, null);

        return $result;
    }

    /**
     * Drops the one connection this client has, and everything built on
     * it. The memo is emptied rather than only dereferenced: anything
     * still holding it would otherwise keep prepared statements alive,
     * and with them the session the server is waiting to discard.
     */
    private function dropConnection(): void
    {
        $this->closed = true;
        $this->preflight = null;
        $this->statements?->clear();
        $this->statements = null;
        $this->pdo = null;
    }

    /**
     * Starts PDO's native transaction on the lazily-opened connection and
     * hands ownership of the client to it. $make receives the connection,
     * the client's own statement memo — the same connection, so a second
     * cache would only re-prepare what this one already holds — and the
     * callback that ends ownership.
     *
     * @param Closure(PDO, PdoStatementCache, Closure(bool): void): PdoTransaction $make
     */
    private function startPdoTransaction(Closure $make): PdoTransaction
    {
        $this->assertNoTransaction();

        try {
            $this->connection()->beginTransaction();
        } catch (PDOException $e) {
            throw new QueryException('Failed to begin transaction: ' . $e->getMessage(), '', $e, PdoError::vendorCode($e));
        }

        $transaction = $make(
            $this->connection(),
            $this->statementCache(),
            function (bool $discard): void {
                $this->owningTransaction = null;

                if ($discard) {
                    // A transaction ended without rolling back on the
                    // wire. This client has one connection and never
                    // reopens it, so dropping it is what hands the work
                    // back to the server to discard with the session.
                    $this->dropConnection();
                }
            },
        );
        $this->owningTransaction = WeakReference::create($transaction);

        return $transaction;
    }

    private function assertNoTransaction(): void
    {
        // A transaction the server ended settles itself the first time
        // it is asked, and its release() clears the reference below — so
        // this probes a live object, never one whose connection has
        // already been handed back.
        if ($this->heldTransaction()?->isActive() !== true) {
            return;
        }

        throw new TransactionException(
            'This client has an open transaction: a PDO client is one connection, so every statement '
            . 'on it would run inside that transaction. Run the work through the transaction until it '
            . 'commits or rolls back.',
        );
    }

    /**
     * The transaction holding this client's connection, while one is
     * alive to hold it. A transaction that has been destroyed released
     * the connection on its way out and cleared the reference with it,
     * so this reads null through the property rather than through a
     * cleared weak reference — and either way, a client whose
     * connection is gone has no transaction on it.
     */
    private function heldTransaction(): ?SqlTransaction
    {
        return $this->owningTransaction?->get();
    }

    private function statementCache(): PdoStatementCache
    {
        return $this->statements ??= new PdoStatementCache();
    }

    /** Which lexical rules this client's pre-flight scans SQL under. */
    private function dialect(): SqlDialect
    {
        return $this instanceof MysqlLink ? SqlDialect::Mysql : SqlDialect::Postgres;
    }

    /**
     * PDO's DSN grammar has no quoting: pdo_mysql splits its DSN on ";",
     * and pdo_pgsql translates every ";" to a space before libpq parses
     * what is left. A ";" inside a value therefore becomes a further
     * connection parameter, and a NUL byte truncates the DSN where the C
     * string ends. Neither can be escaped, so both are refused at
     * construction.
     */
    private static function assertDsnValue(string $name, string $value): void
    {
        if (!\str_contains($value, ';') && !\str_contains($value, "\0")) {
            return;
        }

        throw new InvalidArgumentException(
            "The PDO connection {$name} must not contain \";\" or a NUL byte: PDO's DSN has no way to "
            . 'quote either, so the value would be read as further connection parameters.',
        );
    }

    /** Opens (or returns) the one lazily-created PDO connection. */
    abstract private function connection(): PDO;

    /** Builds the buffered result — dialects differ on lastInsertId. */
    abstract public function buildResult(PDOStatement $statement): BufferedSqlResult;
}
