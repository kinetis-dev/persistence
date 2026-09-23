<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use Kinetis\Persistence\Driver\PdoStatementCache;
use Kinetis\Persistence\Exception\QueryException;
use Kinetis\Persistence\Tests\Fixtures\FakePdoClient;
use Kinetis\Persistence\Tests\Fixtures\InMemoryLogger;
use Kinetis\Persistence\Tests\Fixtures\KillablePdo;
use Kinetis\Persistence\Tests\Fixtures\RecordingSqlInstrumentation;
use Kinetis\Persistence\TransactionGuard;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionObject;

/**
 * A PDOException on the root link costs an ordinary client its session
 * and a single-session client nothing. Proven through
 * {@see FakePdoClient} over {@see KillablePdo}, whose handle stays
 * poisoned once killed, so only a replacement session can serve the
 * next call.
 */
final class PdoSessionRecoveryTest extends TestCase
{
    private RecordingSqlInstrumentation $instrumentation;

    protected function setUp(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('ext-pdo_sqlite provides the connection this test runs PdoExecutionTrait against.');
        }

        $this->instrumentation = new RecordingSqlInstrumentation();
    }

    public function test_an_ordinary_client_replaces_the_session_a_statement_failed_on(): void
    {
        $client = $this->client(singleSession: false);
        $client->execute('INSERT INTO items (name) VALUES (?)', ['a']);
        $killed = self::killedSession($client);
        $cache = self::cacheOf($client);

        $failure = self::failure(static fn () => $client->execute('SELECT name FROM items WHERE id = ?', [1]));

        self::assertSame('57P01', $failure->getSqlState());
        self::assertSame(7, $failure->getCode());
        self::assertSame('SELECT name FROM items WHERE id = ?', $failure->getQuery());
        self::assertInstanceOf(PDOException::class, $failure->getPrevious());
        self::assertSame([['query-2', $failure]], \array_slice($this->instrumentation->reaped, -1));
        self::assertSame([], self::entriesOf($cache));

        self::assertSame(0, $client->query('SELECT COUNT(*) AS c FROM items')->fetchRow()['c']);
        self::assertNotSame($killed, $client->session());
        self::assertFalse($client->isClosed());
    }

    /** The guard's callback never ran, so there is nothing to replay: the next unit of work runs once, on a fresh session. */
    public function test_an_ordinary_client_replaces_a_session_that_refused_to_begin_a_transaction(): void
    {
        $client = $this->client(singleSession: false);
        $killed = self::killedSession($client);
        $guard = new TransactionGuard(new InMemoryLogger());
        $runs = 0;
        $body = static function () use (&$runs): void {
            ++$runs;
        };

        $failure = self::failure(static fn () => $guard->transaction($client, $body));

        self::assertStringStartsWith('Failed to begin transaction: ', $failure->getMessage());
        self::assertSame('57P01', $failure->getSqlState());
        self::assertSame(0, $runs);

        $guard->transaction($client, $body);

        self::assertSame(1, $runs);
        self::assertNotSame($killed, $client->session());
        self::assertFalse($client->isClosed());
    }

    /** Session identity is what a single-session client promises, so an ordinary statement error neither replaces nor closes it. */
    public function test_a_single_session_client_keeps_the_session_a_statement_failed_on(): void
    {
        $client = $this->client(singleSession: true);
        $killed = self::killedSession($client);

        $failure = self::failure(static fn () => $client->query('SELECT 1'));

        self::assertSame('57P01', $failure->getSqlState());
        self::assertSame([['query-1', $failure]], $this->instrumentation->reaped);
        self::assertFalse($client->isClosed());
        self::assertSame($killed, $client->session());
    }

    public function test_a_single_session_client_keeps_a_session_that_refused_to_begin_a_transaction(): void
    {
        $client = $this->client(singleSession: true);
        $killed = self::killedSession($client);

        $failure = self::failure(static fn () => $client->beginTransaction());

        self::assertStringStartsWith('Failed to begin transaction: ', $failure->getMessage());
        self::assertFalse($client->isClosed());
        self::assertSame($killed, $client->session());
    }

    private function client(bool $singleSession): FakePdoClient
    {
        return new FakePdoClient(static function (): PDO {
            $pdo = new KillablePdo('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');

            return $pdo;
        }, $singleSession, $this->instrumentation);
    }

    private static function killedSession(FakePdoClient $client): KillablePdo
    {
        $session = $client->session();
        self::assertInstanceOf(KillablePdo::class, $session);
        $session->kill();

        return $session;
    }

    /** @param callable(): mixed $call */
    private static function failure(callable $call): QueryException
    {
        try {
            $call();
        } catch (QueryException $e) {
            return $e;
        }

        self::fail('Expected the call to fail with a QueryException.');
    }

    private static function cacheOf(FakePdoClient $client): PdoStatementCache
    {
        $cache = new ReflectionObject($client)->getProperty('statements')->getValue($client);
        self::assertInstanceOf(PdoStatementCache::class, $cache);

        return $cache;
    }

    /** @return array<string, mixed> */
    private static function entriesOf(PdoStatementCache $cache): array
    {
        /** @var array<string, mixed> */
        return new ReflectionObject($cache)->getProperty('entries')->getValue($cache);
    }
}
