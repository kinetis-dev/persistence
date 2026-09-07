<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Closure;
use Kinetis\Persistence\ConnectionOptions;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\MysqlTransaction;
use Kinetis\Persistence\Contract\PrefersPreparedStatements;
use Kinetis\Persistence\Exception\ConnectionException;
use Kinetis\Persistence\Exception\QueryException;
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
 * The connection is opened lazily and there is only ever one of it:
 * this client does not pool, so overlapping work is what the
 * {@see MysqliAsyncClient} driver is for. A session that can carry no
 * more work is handed back to the server
 * ({@see PdoExecutionTrait::discardSession()}) and replaced on the next
 * call, unless $singleSession pins the client to the first one.
 */
final class PdoMysqlClient implements MysqlLink, PrefersPreparedStatements
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
        bool $singleSession = false,
    ) {
        $this->singleSession = $singleSession;

        // applicationName is a Postgres concept.
        $this->options = $options ?? new ConnectionOptions();
        $this->options->rejectUnsupported('PDO mysql', ['applicationName']);
        $this->options->validateMysqlSsl('PDO mysql');
        // charset and collation are already held to identifier
        // characters by ConnectionOptions; host and database are this
        // client's own arguments.
        self::assertDsnValue('host', $host);
        self::assertDsnValue('database', $database);
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

    /** @internal Also used by {@see PdoMysqlTransaction} via closure. */
    #[\Override]
    public function buildResult(PDOStatement $statement): BufferedSqlResult
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->columnCount() > 0 ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];

        $result = new BufferedSqlResult(
            $rows,
            $statement->columnCount() > 0 ? \count($rows) : $statement->rowCount(),
            $statement->columnCount() > 0 ? $statement->columnCount() : null,
            MysqlInsertId::normalize($this->connection()->lastInsertId()),
        );

        if ($this->drain($statement)) {
            throw new QueryException(
                'One statement per call: the query produced more than one result set',
                $statement->queryString,
            );
        }

        return $result;
    }

    /**
     * Reads past the first result set and closes the cursor, answering
     * whether there was more than one. A result set left unread fails
     * every later statement on the connection with "cannot execute
     * queries while other unbuffered queries are active". PDO is
     * blocking anyway, so the rest is read here rather than paid for
     * with a discarded session and a fresh connection.
     *
     * A later result set can carry the server's own error — what a
     * procedure raising SIGNAL after a SELECT produces — which is a
     * query failure like any other and is reported as one.
     */
    private function drain(PDOStatement $statement): bool
    {
        $several = false;

        try {
            while ($statement->nextRowset()) {
                $several = true;
            }
        } catch (PDOException $e) {
            $this->closeCursor($statement);

            // PDOStatement carries the text it was built from, which is
            // how a failure this far from the call site still names the
            // statement it belongs to.
            throw new QueryException($e->getMessage(), $statement->queryString, $e, PdoError::vendorCode($e));
        }

        $this->closeCursor($statement);

        return $several;
    }

    /**
     * Closing the cursor is what establishes the session is clean for
     * the next statement. Where even that fails, nothing about the
     * protocol state is established, so the session is given up rather
     * than carrying anything further.
     */
    private function closeCursor(PDOStatement $statement): void
    {
        try {
            $statement->closeCursor();
        } catch (PDOException $e) {
            $this->discardSession();

            throw new ConnectionException(
                'The MySQL connection was discarded: its result state could not be cleared: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    #[\Override]
    private function openConnection(): PDO
    {
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
            // \Pdo\Mysql::ATTR_*, not the equivalent PDO::MYSQL_ATTR_*
            // constants: the two carry identical values, the older form
            // is deprecated as of PHP 8.5, and \Pdo\Mysql resolves on
            // this package's PHP 8.4 floor — so the new form needs no
            // version gate.
            $attributes[\Pdo\Mysql::ATTR_COMPRESS] = true;
        }

        if ($this->options->wantsTls()) {
            // "require" encrypts without verifying the peer;
            // "verify-ca"/"verify-full" verify against the CA bundle.
            // mysqlnd's verification also checks the hostname, so
            // verify-ca behaves as verify-full here — stricter than
            // asked, never looser.
            //
            // The SSL_CA attribute is always present: mysqlnd only
            // initiates TLS when a substantive SSL attribute is set —
            // VERIFY_SERVER_CERT alone leaves the connection in
            // plaintext — and an empty CA path is the minimal trigger
            // for the no-verification mode.
            $attributes[\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT] = $this->options->sslMode !== 'require';
            $attributes[\Pdo\Mysql::ATTR_SSL_CA] = $this->options->sslCa ?? '';

            if ($this->options->sslCert !== null) {
                $attributes[\Pdo\Mysql::ATTR_SSL_CERT] = $this->options->sslCert;
                $attributes[\Pdo\Mysql::ATTR_SSL_KEY] = (string) $this->options->sslKey;
            }
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

        return $pdo;
    }
}
