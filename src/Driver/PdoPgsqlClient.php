<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Closure;
use Kinetis\Persistence\ConnectionOptions;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Contract\PostgresTransaction;
use Kinetis\Persistence\Contract\PrefersPreparedStatements;
use Kinetis\Persistence\Exception\ConnectionException;
use PDO;
use PDOException;
use PDOStatement;

/**
 * A blocking Postgres client over PDO, presenting the same PostgresLink
 * contract as the async driver — the boot-and-die fallback, same
 * rationale as {@see PdoMysqlClient}.
 *
 * PDO's pgsql DSN is handed to libpq as a connection string — every ";"
 * translated to a space first — so the canonical options translate
 * directly to libpq keys, quoted the way libpq expects
 * ({@see LibpqValue}).
 *
 * The connection is opened lazily and there is only ever one of it:
 * this client does not pool, so overlapping work is what the
 * {@see PgsqlAsyncClient} driver is for. A session that can carry no
 * more work is handed back to the server
 * ({@see PdoExecutionTrait::discardSession()}) and replaced on the next
 * call, unless $singleSession pins the client to the first one.
 */
final class PdoPgsqlClient implements PostgresLink, PrefersPreparedStatements
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
        bool $singleSession = false,
    ) {
        $this->singleSession = $singleSession;

        $this->options = $options ?? new ConnectionOptions();
        // Collation and protocol compression are MySQL concepts.
        $this->options->rejectUnsupported('PDO pgsql', ['collation', 'compression']);

        foreach ($this->dsnValues($host, $database) as $name => $value) {
            self::assertDsnValue($name, $value);
        }
    }

    #[\Override]
    public function beginTransaction(): PostgresTransaction
    {
        /** @var PdoPgsqlTransaction */
        return $this->startPdoTransaction(
            fn (PDO $pdo, PdoStatementCache $statements, Closure $endOwnership): PdoTransaction
                => new PdoPgsqlTransaction($pdo, $statements, $this->buildResult(...), $endOwnership),
        );
    }

    /**
     * Every value this client puts in its DSN, by the name an error
     * message should call it.
     *
     * @return array<string, string>
     */
    private function dsnValues(string $host, string $database): array
    {
        $values = ['host' => $host, 'database' => $database];

        foreach ([
            'charset' => $this->options->charset,
            'sslMode' => $this->options->sslMode,
            'sslCa' => $this->options->sslCa,
            'sslCert' => $this->options->sslCert,
            'sslKey' => $this->options->sslKey,
            'applicationName' => $this->options->applicationName,
        ] as $name => $value) {
            if ($value !== null) {
                $values[$name] = $value;
            }
        }

        return $values;
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
     * `abstract private function openConnection(): PDO;`), never
     * directly from this class's own body — static analysis that doesn't
     * resolve trait method calls across the trait boundary will see this
     * as unused; it isn't.
     */
    #[\Override]
    private function openConnection(): PDO
    {
        $quote = LibpqValue::quote(...);
        $dsn = 'pgsql:host=' . $quote($this->host)
            . ';port=' . $this->port
            . ';dbname=' . $quote($this->database);

        if ($this->options->charset !== null) {
            $dsn .= ';client_encoding=' . $quote($this->options->charset);
        }

        if ($this->options->sslMode !== null) {
            $dsn .= ';sslmode=' . $quote($this->options->sslMode);
        }

        if ($this->options->sslCa !== null) {
            $dsn .= ';sslrootcert=' . $quote($this->options->sslCa);
        }

        if ($this->options->sslCert !== null) {
            $dsn .= ';sslcert=' . $quote($this->options->sslCert);
            $dsn .= ';sslkey=' . $quote((string) $this->options->sslKey);
        }

        if ($this->options->connectTimeout !== null) {
            $dsn .= ";connect_timeout={$this->options->connectTimeout}";
        }

        if ($this->options->applicationName !== null) {
            $dsn .= ';application_name=' . $quote($this->options->applicationName);
        }

        try {
            return new PDO($dsn, $this->user, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        } catch (PDOException $e) {
            throw new ConnectionException('Failed to connect to Postgres: ' . $e->getMessage(), 0, $e);
        }
    }
}
