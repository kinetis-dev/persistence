<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Driver\PdoMysqlClient;
use Kinetis\Persistence\Driver\PdoStatementCache;
use Kinetis\Persistence\Exception\QueryException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Why the pre-flight is a contract on the PDO drivers rather than an
 * incidental check: {@see PdoStatementCache} reuses a PDOStatement per
 * SQL string, and a PDOStatement keeps whatever was last bound to it. A
 * call the pre-flight would refuse is exactly the call that would
 * otherwise execute against an earlier call's leftover values.
 *
 * The PDO handle is a test double, which is the point: these cases are
 * settled client-side, and the double proves a refused call neither
 * prepares nor executes anything. What the real servers do with accepted
 * queries is tests/Integration's question.
 */
final class PdoStatementPreflightTest extends TestCase
{
    /**
     * The client and its transaction are two entry points into the same
     * cache, so every case runs through both.
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
        $pdo = $this->pdoDouble($statement);
        $pdo->expects(self::once())->method('prepare');

        $db = $this->link($path, $pdo);
        $db->execute('SELECT ?, ?', [1, 2]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Query has 2 "?" placeholders but 1 parameter was given');

        // Without the pre-flight this executes as [3, 2] — position 2
        // still carrying the value the first call bound to it.
        $db->execute('SELECT ?, ?', [3]);
    }

    /** A rejected call leaves the cache exactly as it found it. */
    #[DataProvider('paths')]
    public function test_a_rejected_call_leaves_the_statement_cache_usable(string $path): void
    {
        $statement = $this->statementDouble();
        $statement->expects(self::once())->method('execute');
        $pdo = $this->pdoDouble($statement);
        $pdo->expects(self::once())->method('prepare');

        $db = $this->link($path, $pdo);

        try {
            $db->execute('SELECT ?', [new \stdClass()]);
            self::fail('Expected the unbindable value to be rejected.');
        } catch (QueryException) {
        }

        $db->execute('SELECT ?', [1]);
    }

    /**
     * A transaction runs on the client's own connection, so it uses the
     * client's own memo: a second cache would re-prepare, on the same
     * connection, statements the first one already holds.
     */
    public function test_a_transaction_shares_the_clients_statement_cache(): void
    {
        $statement = $this->statementDouble();
        $statement->expects(self::exactly(2))->method('execute');
        $pdo = $this->pdoDouble($statement);
        // One prepare for the SQL string, across both entry points.
        $pdo->expects(self::once())->method('prepare');

        $client = $this->client($pdo);
        $client->execute('SELECT ?', [1]);

        $transaction = $client->beginTransaction();
        $transaction->execute('SELECT ?', [2]);
        $transaction->rollback();
    }

    /** @return MockObject&PDO */
    private function pdoDouble(PDOStatement $statement): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $pdo->method('inTransaction')->willReturn(true);

        return $pdo;
    }

    /** @return MockObject&PDOStatement */
    private function statementDouble(): PDOStatement
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('columnCount')->willReturn(0);
        $statement->method('rowCount')->willReturn(1);
        $statement->method('nextRowset')->willReturn(false);

        return $statement;
    }

    /** The client itself, or a transaction on it. */
    private function link(string $path, PDO $pdo): SqlLink
    {
        $client = $this->client($pdo);

        return $path === 'transaction' ? $client->beginTransaction() : $client;
    }

    private function client(PDO $pdo): PdoMysqlClient
    {
        $client = new PdoMysqlClient('localhost', 'user', 'password', 'db');
        // The client opens its own connection lazily and never reopens
        // it, so seating the handle is all it takes to reach execute()
        // without a server.
        new ReflectionProperty(PdoMysqlClient::class, 'pdo')->setValue($client, $pdo);

        return $client;
    }
}
