<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Driver\BufferedSqlResult;
use Kinetis\Persistence\Driver\PdoMysqlClient;
use Kinetis\Persistence\Driver\PdoMysqlTransaction;
use Kinetis\Persistence\Driver\PdoPgsqlClient;
use Kinetis\Persistence\Driver\PdoPgsqlTransaction;
use Kinetis\Persistence\Exception\QueryException;
use Kinetis\Persistence\Tests\Fixtures\StringableParameter;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;

/**
 * The pre-flight every PDO execution passes before a statement is
 * prepared, bound or executed: an argument list keyed by position,
 * exactly one argument per recognized "?", every value one of the five
 * kinds a driver can bind, and no "?" inside a MySQL executable
 * comment. The first three matter most on the second call for the same
 * SQL, where the statement is reused and still holds whatever was bound
 * to it last.
 *
 * The PDO handle is a test double here, which is the whole point: what
 * these cases decide is settled entirely client-side, before a server
 * is ever reached, and the double is what proves it — a rejected call
 * neither prepares nor executes anything. What the real servers do with
 * the queries that *are* accepted is a different question, answered by
 * tests/Integration against MySQL, MariaDB and Postgres.
 */
final class PdoStatementPreflightTest extends TestCase
{
    /** The exact diagnostic {@see \Kinetis\Persistence\Driver\SqlParamInterpolator::rejectPlaceholderInsideExecutableComment()} throws. */
    private const string EXECUTABLE_COMMENT_PLACEHOLDER_MESSAGE = 'A "?" placeholder cannot appear inside a '
        . 'version-gated executable comment (/*!...*/ or /*M!...*/) — whether it is live depends on the '
        . 'connected server\'s own version, which the native and PDO drivers would resolve differently for '
        . 'the same query. Move the bound value outside the comment.';

    /** The exact diagnostic {@see \Kinetis\Persistence\Driver\SqlParamInterpolator::assertPositionalKeys()} throws. */
    private const string NON_LIST_PARAMS_MESSAGE = 'Query parameters must be a list keyed 0..n-1 in '
        . 'placeholder order; an associative or sparse array is rejected rather than reindexed, so a '
        . 'mis-keyed argument list surfaces at the call site instead of binding somewhere unintended.';

    /**
     * The client and the transaction keep separate statement caches and
     * are separate code paths into the same one — every case below runs
     * against both.
     *
     * @return iterable<string, array{string}>
     */
    public static function paths(): iterable
    {
        yield 'client' => ['client'];
        yield 'transaction' => ['transaction'];
    }

    #[DataProvider('paths')]
    public function test_a_reused_statement_rejects_a_shorter_argument_list(string $path): void
    {
        $statement = $this->statementDouble();
        // Prepared once for the SQL string, executed once for the call
        // that matched it: the rejected second call adds neither.
        $statement->expects(self::once())->method('execute');
        $pdo = $this->pdoDouble($statement, self::once());

        $db = $this->mysqlLink($path, $pdo);
        $db->execute('SELECT ?, ?', [1, 2]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Query has 2 "?" placeholders but 1 parameter was given');

        // Without the pre-flight this executes as [3, 2] — position 2
        // still carrying the value the first call bound to it.
        $db->execute('SELECT ?, ?', [3]);
    }

    #[DataProvider('paths')]
    public function test_a_reused_statement_rejects_a_longer_argument_list(string $path): void
    {
        $statement = $this->statementDouble();
        $statement->expects(self::once())->method('execute');
        $pdo = $this->pdoDouble($statement, self::once());

        $db = $this->mysqlLink($path, $pdo);
        $db->execute('SELECT ?, ?', [1, 2]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Query has 2 "?" placeholders but 3 parameters were given');

        $db->execute('SELECT ?, ?', [1, 2, 3]);
    }

    #[DataProvider('paths')]
    public function test_a_mismatched_first_call_is_rejected_before_anything_is_prepared(string $path): void
    {
        $statement = $this->statementDouble();
        $statement->expects(self::never())->method('execute');
        $pdo = $this->pdoDouble($statement, self::never());

        $db = $this->mysqlLink($path, $pdo);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Query has 1 "?" placeholder but 2 parameters were given');

        $db->execute('SELECT ?', [1, 2]);
    }

    /**
     * A rejected call leaves the cache exactly as it found it, so the
     * correct call that follows still prepares its statement.
     */
    #[DataProvider('paths')]
    public function test_a_rejected_call_does_not_poison_the_statement_cache(string $path): void
    {
        $statement = $this->statementDouble();
        $statement->expects(self::once())->method('execute');
        $pdo = $this->pdoDouble($statement, self::once());

        $db = $this->mysqlLink($path, $pdo);

        try {
            $db->execute('SELECT ?', []);
            self::fail('Expected the argument count to be rejected.');
        } catch (QueryException $e) {
            self::assertSame('Query has 1 "?" placeholder but 0 parameters were given', $e->getMessage());
        }

        $db->execute('SELECT ?', [1]);
    }

    /**
     * Keys are read before the count, before the memo and before
     * prepare(), so a non-list reaches neither the server nor the
     * binder — the two driver families never get the chance to disagree
     * about an argument list none of them accepts.
     *
     * @param array<array-key, mixed> $params
     */
    #[DataProvider('nonListArgumentArrays')]
    public function test_a_non_list_argument_array_is_rejected_before_anything_is_prepared(string $path, array $params): void
    {
        $statement = $this->statementDouble();
        $statement->expects(self::never())->method('bindValue');
        $statement->expects(self::never())->method('execute');
        $pdo = $this->pdoDouble($statement, self::never());

        $db = $this->mysqlLink($path, $pdo);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(self::NON_LIST_PARAMS_MESSAGE);

        $db->execute('SELECT ?, ?', $params);
    }

    /**
     * Every keying array_is_list() refuses, against both paths: string
     * keys, a gap between numeric ones, and numeric ones out of order.
     *
     * @return iterable<string, array{string, array<array-key, mixed>}>
     */
    public static function nonListArgumentArrays(): iterable
    {
        foreach (['client', 'transaction'] as $path) {
            yield "{$path}: string keys" => [$path, ['a' => 1, 'b' => 2]];
            yield "{$path}: sparse numeric keys" => [$path, [0 => 1, 2 => 2]];
            yield "{$path}: numeric keys out of order" => [$path, [1 => 1, 0 => 2]];
        }
    }

    /**
     * The keying check sits ahead of the memo lookup, so a cache hit is
     * held to it as strictly as a first call — and the rejection leaves
     * the memoized statement untouched for the next correct one.
     */
    #[DataProvider('paths')]
    public function test_a_reused_statement_rejects_a_non_list_and_stays_usable(string $path): void
    {
        $statement = $this->statementDouble();
        $statement->expects(self::exactly(2))->method('execute');
        $pdo = $this->pdoDouble($statement, self::once());

        $db = $this->mysqlLink($path, $pdo);
        $db->execute('SELECT ?, ?', [1, 2]);

        try {
            $db->execute('SELECT ?, ?', ['a' => 1, 'b' => 2]);
            self::fail('Expected the argument keys to be rejected.');
        } catch (QueryException $e) {
            self::assertSame(self::NON_LIST_PARAMS_MESSAGE, $e->getMessage());
        }

        $db->execute('SELECT ?, ?', [3, 4]);
    }

    /**
     * Too few arguments is the same count diagnostic on a first call as
     * on a reused statement, and on the PDO drivers as on the native
     * ones — the contract is the count, never how far a driver got
     * before noticing.
     *
     * @param 'mysql'|'postgres' $dialect
     */
    #[DataProvider('pathsAndDialects')]
    public function test_too_few_arguments_are_rejected_before_anything_is_prepared(string $path, string $dialect): void
    {
        $statement = $this->statementDouble();
        $statement->expects(self::never())->method('bindValue');
        $statement->expects(self::never())->method('execute');
        $pdo = $this->pdoDouble($statement, self::never());

        $db = $this->link($dialect, $path, $pdo);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Query has 3 "?" placeholders but 1 parameter was given');

        $db->execute('SELECT ?, ?, ?', [1]);
    }

    /**
     * A value outside the contract is refused ahead of prepare() and
     * ahead of the first bindValue(), on both dialects and both paths
     * — the double is what proves it reaches neither.
     *
     * @param 'mysql'|'postgres' $dialect
     */
    #[DataProvider('pathsAndDialects')]
    public function test_an_unsupported_value_is_rejected_before_anything_is_prepared(string $path, string $dialect): void
    {
        $stream = \fopen('php://memory', 'r');
        self::assertIsResource($stream);

        foreach (self::unbindableValues($stream) as $label => [$value, $message]) {
            $statement = $this->statementDouble();
            $statement->expects(self::never())->method('bindValue');
            $statement->expects(self::never())->method('execute');
            $pdo = $this->pdoDouble($statement, self::never());

            $db = $this->link($dialect, $path, $pdo);

            try {
                $db->execute('SELECT ?, ?', ['fine', $value]);
                self::fail("Expected {$label} to be rejected on {$dialect} ({$path}).");
            } catch (QueryException $e) {
                self::assertSame($message, $e->getMessage(), "{$label} on {$dialect} ({$path})");
            }
        }

        \fclose($stream);
    }

    /**
     * The value check reads the whole list before binding any of it, so
     * a reused statement keeps exactly the bindings its last accepted
     * call made — the rejected call neither rebinds a position nor
     * leaves one half-updated — and the next correct call still runs.
     */
    #[DataProvider('paths')]
    public function test_a_reused_statement_binds_nothing_when_one_value_is_unsupported(string $path): void
    {
        $statement = $this->statementDouble();
        // Two positions per accepted call, and none for the rejected one
        // between them.
        $statement->expects(self::exactly(4))->method('bindValue');
        $statement->expects(self::exactly(2))->method('execute');
        $pdo = $this->pdoDouble($statement, self::once());

        $db = $this->mysqlLink($path, $pdo);
        $db->execute('SELECT ?, ?', [1, 2]);

        try {
            $db->execute('SELECT ?, ?', [3, [4]]);
            self::fail('Expected the unsupported value to be rejected.');
        } catch (QueryException $e) {
            self::assertSame(
                'Parameter at index 1 is of type array; only null, bool, int, finite float and string can be bound.',
                $e->getMessage(),
            );
        }

        $db->execute('SELECT ?, ?', [5, 6]);
    }

    /**
     * The count is settled first, so a call that is both short and
     * carrying an unsupported value reads the same everywhere.
     */
    #[DataProvider('paths')]
    public function test_the_argument_count_is_settled_before_any_value_is_examined(string $path): void
    {
        $statement = $this->statementDouble();
        $statement->expects(self::never())->method('execute');
        $pdo = $this->pdoDouble($statement, self::never());

        $db = $this->mysqlLink($path, $pdo);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Query has 2 "?" placeholders but 1 parameter was given');

        $db->execute('SELECT ?, ?', [\NAN]);
    }

    /**
     * The five kinds a driver can bind all reach bindValue(), so the
     * check narrows nothing a caller legitimately binds.
     */
    #[DataProvider('paths')]
    public function test_every_supported_value_kind_reaches_the_binder(string $path): void
    {
        $statement = $this->statementDouble();
        $statement->expects(self::exactly(6))->method('bindValue');
        $statement->expects(self::once())->method('execute');
        $pdo = $this->pdoDouble($statement, self::once());

        $this->mysqlLink($path, $pdo)->execute('SELECT ?, ?, ?, ?, ?, ?', [null, true, false, 7, 1.5, 'x']);
    }

    /**
     * A dollar-quote tag is spelled with Postgres's own
     * unquoted-identifier bytes, non-ASCII included — so the PDO
     * pre-flight reads the "?" inside "$é$ ... $é$" as data and still
     * bind-checks the one outside it.
     */
    #[DataProvider('paths')]
    public function test_a_non_ascii_dollar_quote_hides_its_question_mark_from_the_preflight(string $path): void
    {
        $statement = $this->statementDouble();
        $statement->expects(self::once())->method('bindValue');
        $statement->expects(self::once())->method('execute');
        $pdo = $this->pdoDouble($statement, self::once());

        $this->postgresLink($path, $pdo)->execute('SELECT $é$ ? $é$ AS v WHERE c = ?', [1]);
    }

    #[DataProvider('paths')]
    public function test_a_placeholder_outside_a_non_ascii_dollar_quote_is_still_counted(string $path): void
    {
        $statement = $this->statementDouble();
        $statement->expects(self::never())->method('execute');
        $pdo = $this->pdoDouble($statement, self::never());

        $db = $this->postgresLink($path, $pdo);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Query has 1 "?" placeholder but 2 parameters were given');

        $db->execute('SELECT $é$ ? $é$ AS v WHERE c = ?', [1, 2]);
    }

    /**
     * Both execution paths, against both dialects.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function pathsAndDialects(): iterable
    {
        foreach (['client', 'transaction'] as $path) {
            foreach (['mysql', 'postgres'] as $dialect) {
                yield "{$path}: {$dialect}" => [$path, $dialect];
            }
        }
    }

    /**
     * Every value the shared contract refuses, with the exact
     * diagnostic it refuses it with. Built here rather than in a data
     * provider so the resource case is a live handle.
     *
     * @param resource $stream
     * @return iterable<string, array{mixed, string}>
     */
    private static function unbindableValues(mixed $stream): iterable
    {
        $unsupported = static fn (string $type): string => "Parameter at index 1 is of type {$type}; "
            . 'only null, bool, int, finite float and string can be bound.';
        $nonFinite = 'Parameter at index 1 is a non-finite float; only a finite float can be bound.';

        yield 'array' => [[1, 2], $unsupported('array')];
        yield 'object' => [new stdClass(), $unsupported('stdClass')];
        yield 'stringable object' => [new StringableParameter(), $unsupported(StringableParameter::class)];
        yield 'closure' => [static fn (): int => 1, $unsupported('Closure')];
        yield 'resource' => [$stream, $unsupported('resource (stream)')];
        yield 'INF' => [\INF, $nonFinite];
        yield 'NAN' => [\NAN, $nonFinite];
    }

    /** @param 'mysql'|'postgres' $dialect */
    private function link(string $dialect, string $path, PDO $pdo): SqlLink
    {
        return $dialect === 'mysql' ? $this->mysqlLink($path, $pdo) : $this->postgresLink($path, $pdo);
    }

    #[DataProvider('paths')]
    public function test_a_placeholder_inside_a_mysql_executable_comment_is_rejected(string $path): void
    {
        $statement = $this->statementDouble();
        $statement->expects(self::never())->method('execute');
        $pdo = $this->pdoDouble($statement, self::never());

        $db = $this->mysqlLink($path, $pdo);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(self::EXECUTABLE_COMMENT_PLACEHOLDER_MESSAGE);

        $db->execute('SELECT /*!00000 ? */ 1', [1]);
    }

    #[DataProvider('paths')]
    public function test_a_placeholder_inside_a_mariadb_executable_comment_is_rejected(string $path): void
    {
        $statement = $this->statementDouble();
        $statement->expects(self::never())->method('execute');
        $pdo = $this->pdoDouble($statement, self::never());

        $db = $this->mysqlLink($path, $pdo);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(self::EXECUTABLE_COMMENT_PLACEHOLDER_MESSAGE);

        $db->execute('SELECT /*M!00000 ? */ 1', [1]);
    }

    /**
     * Postgres has no executable-comment convention, so the same text is
     * an ordinary comment there and the "?" inside it is not a slot —
     * the dialect difference the shared scanner already applies for the
     * native drivers, reaching the PDO drivers through the same code.
     */
    #[DataProvider('paths')]
    public function test_postgres_reads_the_same_comment_as_inert_and_binds_nothing(string $path): void
    {
        $statement = $this->statementDouble();
        $statement->expects(self::once())->method('execute');
        $pdo = $this->pdoDouble($statement, self::once());

        $this->postgresLink($path, $pdo)->execute('SELECT /*!00000 ? */ 1', []);
    }

    /**
     * A "?" that the scanner reads as data needs no argument, and asking
     * for one is the mismatch — the arity check counts placeholders the
     * way the native drivers substitute them, never a bare occurrence of
     * the character.
     *
     * @param list<mixed> $params
     */
    #[DataProvider('lexicallyInertQuestionMarks')]
    public function test_a_question_mark_that_is_data_is_not_counted_as_a_placeholder(string $dialect, string $sql, array $params): void
    {
        $statement = $this->statementDouble();
        $statement->expects(self::once())->method('execute');
        $pdo = $this->pdoDouble($statement, self::once());

        $db = $dialect === 'mysql' ? $this->mysqlLink('client', $pdo) : $this->postgresLink('client', $pdo);

        $db->execute($sql, $params);
    }

    /**
     * @return iterable<string, array{string, string, list<mixed>}>
     */
    public static function lexicallyInertQuestionMarks(): iterable
    {
        yield 'single-quoted string' => ['mysql', "SELECT 'a?b' AS v, ? AS w", [1]];
        yield 'backtick identifier' => ['mysql', 'SELECT `weird?col` FROM t WHERE c = ?', [1]];
        yield 'line comment' => ['mysql', "SELECT 1 -- ?\nWHERE c = ?", [1]];
        yield 'hash comment' => ['mysql', "SELECT 1 # ?\nWHERE c = ?", [1]];
        yield 'block comment' => ['mysql', 'SELECT /* ? */ ? AS v', [1]];
        yield 'doubled literal' => ['postgres', 'SELECT 1 WHERE data ?? ? ', [1]];
        yield 'dollar quoted' => ['postgres', 'SELECT $$what about ?$$ WHERE c = ?', [1]];
        yield 'tagged dollar quoted' => ['postgres', 'SELECT $body$? $other$ ?$body$ WHERE c = ?', [1]];
        yield 'non-ascii dollar quoted' => ['postgres', 'SELECT $é$ ? $é$ WHERE c = ?', [1]];
    }

    /**
     * @param InvocationOrder $prepareCount How many prepares this case
     *     expects — the difference between reusing a statement and
     *     refusing the call before there is one.
     * @return MockObject&PDO
     */
    private function pdoDouble(PDOStatement $statement, InvocationOrder $prepareCount): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($prepareCount)->method('prepare')->willReturn($statement);

        return $pdo;
    }

    /** @return MockObject&PDOStatement */
    private function statementDouble(): PDOStatement
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('columnCount')->willReturn(0);
        $statement->method('rowCount')->willReturn(1);

        return $statement;
    }

    /** A MySQL client, or a MySQL transaction, over the given handle. */
    private function mysqlLink(string $path, PDO $pdo): SqlLink
    {
        if ($path === 'transaction') {
            return new PdoMysqlTransaction($pdo, self::buildResult());
        }

        $client = new PdoMysqlClient('localhost', 'user', 'password', 'db');
        // The client opens its own connection lazily and never reopens
        // it, so seating the handle is all it takes to reach execute()
        // without a server.
        new ReflectionProperty(PdoMysqlClient::class, 'pdo')->setValue($client, $pdo);

        return $client;
    }

    /** A Postgres client, or a Postgres transaction, over the given handle. */
    private function postgresLink(string $path, PDO $pdo): SqlLink
    {
        if ($path === 'transaction') {
            return new PdoPgsqlTransaction($pdo, self::buildResult());
        }

        $client = new PdoPgsqlClient('localhost', 'user', 'password', 'db');
        new ReflectionProperty(PdoPgsqlClient::class, 'pdo')->setValue($client, $pdo);

        return $client;
    }

    /** Stands in for the owning client's own result construction. */
    private static function buildResult(): callable
    {
        return static fn (PDOStatement $statement): BufferedSqlResult => new BufferedSqlResult([], 0, null);
    }
}
