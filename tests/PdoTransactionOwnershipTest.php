<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use Closure;
use Fiber;
use Kinetis\Persistence\Driver\PdoStatementCache;
use Kinetis\Persistence\Exception\ConnectionException;
use Kinetis\Persistence\Exception\TransactionException;
use Kinetis\Persistence\Tests\Fixtures\FakePdoClient;
use Kinetis\Persistence\Tests\Fixtures\RollbackRefusingPdo;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionObject;
use Throwable;
use WeakReference;

/**
 * A PDO client is one connection, so a transaction owns the whole client
 * while it lasts — and the client outlives any one session it holds.
 * Proven against in-memory SQLite connections through
 * {@see FakePdoClient}, which runs the same PdoExecutionTrait both real
 * PDO clients do.
 */
final class PdoTransactionOwnershipTest extends TestCase
{
    private PDO $pdo;

    private FakePdoClient $client;

    protected function setUp(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('ext-pdo_sqlite provides the connection this test runs PdoExecutionTrait against.');
        }

        $this->client = FakePdoClient::overSqlite();
        // The session the client itself is on, which is what the tests
        // below reach around it to break.
        $this->pdo = $this->client->session();
    }

    public function test_the_root_link_is_refused_while_a_transaction_is_open(): void
    {
        $transaction = $this->client->beginTransaction();

        foreach ([
            fn () => $this->client->execute('INSERT INTO items (name) VALUES (?)', ['a']),
            fn () => $this->client->query('SELECT 1'),
        ] as $call) {
            try {
                $call();
                self::fail('Expected the root link to be refused.');
            } catch (TransactionException $e) {
                self::assertStringContainsString('This client has an open transaction', $e->getMessage());
            }
        }

        $transaction->rollback();
    }

    /** The owning Fiber is refused too: the connection is the unit of ownership here. */
    public function test_the_root_link_is_refused_from_every_fiber(): void
    {
        $transaction = $this->client->beginTransaction();
        $caught = null;

        $fiber = new Fiber(function () use (&$caught): void {
            try {
                $this->client->query('SELECT 1');
            } catch (Throwable $e) {
                $caught = $e;
            }
        });
        $fiber->start();

        self::assertInstanceOf(TransactionException::class, $caught);
        $transaction->rollback();
    }

    public function test_a_second_transaction_is_refused(): void
    {
        $transaction = $this->client->beginTransaction();

        try {
            $this->client->beginTransaction();
            self::fail('Expected the second transaction to be refused.');
        } catch (TransactionException $e) {
            self::assertStringContainsString('This client has an open transaction', $e->getMessage());
        }

        $transaction->rollback();
    }

    public function test_the_root_link_works_again_after_the_transaction_ends(): void
    {
        $this->client->beginTransaction()->commit();

        $this->client->execute('INSERT INTO items (name) VALUES (?)', ['a']);

        self::assertSame(1, $this->client->query('SELECT COUNT(*) AS c FROM items')->fetchRow()['c']);
    }

    /** Rolling back really rolls back: the transaction shares the client's one connection. */
    public function test_a_rolled_back_transaction_leaves_nothing_behind(): void
    {
        $transaction = $this->client->beginTransaction();
        $transaction->execute('INSERT INTO items (name) VALUES (?)', ['a']);
        $transaction->rollback();

        self::assertSame(0, $this->client->query('SELECT COUNT(*) AS c FROM items')->fetchRow()['c']);
    }

    /**
     * Closing from another Fiber cannot put a ROLLBACK on a connection
     * the owner may be using, so it hands the session back to the
     * server instead, which rolls the work back as the session goes.
     * The client keeps none of it: the next call opens a fresh session
     * and serves the caller on that.
     */
    public function test_a_foreign_close_discards_the_session_and_the_client_opens_a_fresh_one(): void
    {
        $transaction = $this->client->beginTransaction();
        $transaction->execute('INSERT INTO items (name) VALUES (?)', ['a']);

        new Fiber(static fn () => $transaction->close())->start();

        self::assertFalse($transaction->isActive());
        self::assertFalse($this->client->isClosed());

        $replacement = $this->client->session();
        self::assertNotSame($this->pdo, $replacement);
        self::assertSame(0, $this->client->query('SELECT COUNT(*) AS c FROM items')->fetchRow()['c']);
    }

    /**
     * A transaction is over wherever its session went. Losing the
     * session is a terminal transition like any other, so the object
     * stays ended and refuses statements even though the client behind
     * it has moved on to a new one.
     */
    public function test_a_transaction_whose_session_was_discarded_stays_ended(): void
    {
        $transaction = $this->client->beginTransaction();

        new Fiber(static fn () => $transaction->close())->start();
        $this->client->query('SELECT 1');

        self::assertFalse($transaction->isActive());
        self::assertTrue($transaction->isClosed());

        try {
            $transaction->execute('INSERT INTO items (name) VALUES (?)', ['a']);
            self::fail('Expected the ended transaction to refuse the statement.');
        } catch (TransactionException $e) {
            self::assertStringContainsString('no longer open', $e->getMessage());
        }

        $transaction->close();
        self::assertFalse($this->client->isClosed());
    }

    /**
     * close() is the one ending a client does not come back from, and a
     * discarded session before it changes nothing about that: an
     * ordinary client connects again until close(), never after it.
     */
    public function test_close_stays_final_after_a_session_has_been_discarded(): void
    {
        $transaction = $this->client->beginTransaction();
        new Fiber(static fn () => $transaction->close())->start();
        $this->client->query('SELECT 1');

        $this->client->close();

        self::assertTrue($this->client->isClosed());

        foreach ([
            fn () => $this->client->query('SELECT 1'),
            fn () => $this->client->execute('INSERT INTO items (name) VALUES (?)', ['a']),
            fn () => $this->client->beginTransaction(),
        ] as $call) {
            try {
                $call();
                self::fail('Expected the closed client to refuse the call.');
            } catch (ConnectionException $e) {
                self::assertStringContainsString('closed', $e->getMessage());
            }
        }
    }

    /**
     * The other session policy, which kinetis/migrations runs on: what a
     * single-session client holds — an advisory lock — lives in the
     * session, so losing it closes the client. The caller finds out,
     * instead of carrying on against an unlocked replacement.
     */
    public function test_a_single_session_client_closes_when_its_session_is_discarded(): void
    {
        $client = FakePdoClient::overSqlite(singleSession: true);
        $transaction = $client->beginTransaction();

        new Fiber(static fn () => $transaction->close())->start();

        self::assertTrue($client->isClosed());

        try {
            $client->query('SELECT 1');
            self::fail('Expected the client to refuse the statement.');
        } catch (ConnectionException $e) {
            self::assertStringContainsString('one database session', $e->getMessage());
        }
    }

    /**
     * The statements the old session's memo held were prepared on that
     * session, so the replacement gets a memo of its own rather than
     * inheriting handles the server has already discarded.
     */
    public function test_a_replacement_session_gets_its_own_statement_memo(): void
    {
        $this->client->execute('INSERT INTO items (name) VALUES (?)', ['a']);
        $first = self::cacheOf($this->client);

        $transaction = $this->client->beginTransaction();
        new Fiber(static fn () => $transaction->close())->start();
        $this->client->execute('INSERT INTO items (name) VALUES (?)', ['b']);

        self::assertNotSame($first, self::cacheOf($this->client));
        self::assertCount(1, self::statementMemoOf($this->client));
    }

    /**
     * A transaction begun directly on the client and dropped without
     * commit(), rollback() or close(). The client is not what keeps it
     * alive, so the last reference going away destroys it, and that is
     * where it gives the session up: nothing can be sent from there, so
     * the session goes back to the server, which rolls the work back
     * with it.
     *
     * Holding it instead would keep that session, and the locks the
     * abandoned transaction is sitting on, for the rest of the client's
     * life. `DB_DRIVER=auto` picks PDO for every process that is not a
     * persistent worker, a queue worker included, where that life runs
     * far past one request — so what the client is left with is a fresh
     * session, not none at all.
     */
    public function test_dropping_an_abandoned_transaction_replaces_the_session(): void
    {
        $transaction = $this->client->beginTransaction();
        $transaction->execute('INSERT INTO items (name) VALUES (?)', ['a']);
        $cache = self::cacheOf($this->client);
        $probe = WeakReference::create($transaction);

        unset($transaction);

        self::assertNull($probe->get());
        self::assertSame([], self::entriesOf($cache));
        self::assertFalse($this->client->isClosed());

        self::assertNotSame($this->pdo, $this->client->session());
        self::assertSame(0, $this->client->query('SELECT COUNT(*) AS c FROM items')->fetchRow()['c']);
    }

    /**
     * The control, and what the weak reference above is for: ownership
     * stands for as long as anything else holds the transaction, so
     * nothing is given up early. A client holding its own transaction
     * would be that holder for its whole life, and the root link would
     * never be usable again.
     */
    public function test_a_transaction_something_else_still_holds_keeps_the_client(): void
    {
        $transaction = $this->client->beginTransaction();
        $held = [$transaction];
        unset($transaction);

        self::assertFalse($this->client->isClosed());

        try {
            $this->client->query('SELECT 1');
            self::fail('Expected the root link to be refused.');
        } catch (TransactionException $e) {
            self::assertStringContainsString('This client has an open transaction', $e->getMessage());
        }

        $held[0]->rollback();

        self::assertSame(0, $this->client->query('SELECT COUNT(*) AS c FROM items')->fetchRow()['c']);
    }

    /**
     * A completed transaction someone kept a variable pointing at holds
     * nothing of the session: not the handle, not the closures bound to
     * the client, and not the statement memo — a PDOStatement keeps its
     * connection open as surely as the handle does, so a retained object
     * holding one would hold the session, and its locks, with it.
     */
    public function test_a_completed_transaction_retains_nothing_of_the_connection(): void
    {
        foreach ([
            'commit' => static fn (object $transaction): mixed => $transaction->commit(),
            'rollback' => static fn (object $transaction): mixed => $transaction->rollback(),
            'close' => static fn (object $transaction): mixed => $transaction->close(),
        ] as $ending => $end) {
            $transaction = $this->client->beginTransaction();
            $transaction->execute('INSERT INTO items (name) VALUES (?)', ['a']);
            $end($transaction);

            self::assertSame([], self::connectionReferencesOf($transaction), "after {$ending}");
        }
    }

    /**
     * An ordinary commit leaves the client's own memo alone: the
     * connection lives on, and the statements prepared on it are still
     * worth reusing.
     */
    public function test_an_ordinary_commit_keeps_the_client_statement_memo(): void
    {
        $transaction = $this->client->beginTransaction();
        $transaction->execute('INSERT INTO items (name) VALUES (?)', ['a']);
        $transaction->commit();

        self::assertCount(1, self::statementMemoOf($this->client));
    }

    /**
     * Discarding empties it instead. Anything still holding the memo
     * would otherwise keep prepared statements — and with them the
     * session the server is waiting to discard — alive.
     */
    public function test_discarding_empties_the_client_statement_memo(): void
    {
        $transaction = $this->client->beginTransaction();
        $transaction->execute('INSERT INTO items (name) VALUES (?)', ['a']);
        $cache = self::cacheOf($this->client);

        new Fiber(static fn () => $transaction->close())->start();

        self::assertSame([], self::entriesOf($cache));
        self::assertSame([], self::connectionReferencesOf($transaction));
    }

    /**
     * Closing the client ends the transaction holding it first: both run
     * on the same connection, and dropping the client's references while
     * the transaction still held its own would leave the session open
     * with nothing able to end it.
     */
    public function test_closing_the_client_terminalizes_its_transaction(): void
    {
        $transaction = $this->client->beginTransaction();
        $transaction->execute('INSERT INTO items (name) VALUES (?)', ['a']);
        $cache = self::cacheOf($this->client);

        $this->client->close();

        self::assertFalse($transaction->isActive());
        self::assertSame([], self::connectionReferencesOf($transaction));
        self::assertSame([], self::entriesOf($cache));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) AS c FROM items')->fetch(PDO::FETCH_ASSOC)['c']);
    }

    public function test_closing_the_client_twice_is_a_no_op(): void
    {
        $this->client->beginTransaction();

        $this->client->close();
        $this->client->close();

        self::assertTrue($this->client->isClosed());
    }

    /**
     * A rollback the server refuses is reported, and changes nothing
     * about the cleanup: the client is closed and the transaction holds
     * nothing either way, since neither can be used again.
     */
    public function test_a_refused_rollback_still_drops_the_connection(): void
    {
        $client = new FakePdoClient(static function (): PDO {
            $pdo = new RollbackRefusingPdo('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');

            return $pdo;
        });
        $transaction = $client->beginTransaction();
        $transaction->execute('INSERT INTO items (name) VALUES (?)', ['a']);

        try {
            $client->close();
            self::fail('Expected the refused rollback to propagate.');
        } catch (TransactionException $e) {
            self::assertStringContainsString('Rollback failed', $e->getMessage());
        }

        self::assertTrue($client->isClosed());
        self::assertFalse($transaction->isActive());
        self::assertSame([], self::connectionReferencesOf($transaction));
    }

    /**
     * PDO::inTransaction() reads the transaction status the server sent
     * with the last packet, so a transaction the server ended — which on
     * MySQL any DDL statement does — is visible here. Rolling the
     * connection back underneath the object stands in for that: what
     * matters is that the next statement is refused rather than run in
     * autocommit.
     */
    public function test_a_transaction_the_server_ended_refuses_further_statements(): void
    {
        $transaction = $this->client->beginTransaction();
        $this->pdo->rollBack();

        self::assertFalse($transaction->isActive());

        try {
            $transaction->execute('INSERT INTO items (name) VALUES (?)', ['a']);
            self::fail('Expected the ended transaction to refuse the statement.');
        } catch (TransactionException) {
        }

        // Ownership settled with it, so the root link is usable again.
        $this->client->execute('INSERT INTO items (name) VALUES (?)', ['b']);
        self::assertSame(1, $this->client->query('SELECT COUNT(*) AS c FROM items')->fetchRow()['c']);
    }

    /**
     * Inspecting such a transaction is what settles it: isActive() hands
     * the connection back rather than only reporting the transaction
     * gone. Nothing closes the object afterwards — TransactionGuard's
     * disposal sweep skips a transaction that answers false — so a
     * client left owned would refuse every later statement and every
     * later transaction for the rest of its life.
     */
    public function test_inspecting_a_server_ended_transaction_releases_the_client(): void
    {
        $transaction = $this->client->beginTransaction();
        $this->pdo->rollBack();

        self::assertTrue($transaction->isClosed());

        $second = $this->client->beginTransaction();
        $second->execute('INSERT INTO items (name) VALUES (?)', ['a']);
        $second->commit();

        self::assertFalse($this->client->isClosed());
        self::assertSame(1, $this->client->query('SELECT COUNT(*) AS c FROM items')->fetchRow()['c']);
    }

    /**
     * Every property of $transaction still pointing at the physical
     * session — the handle itself, the memo of statements prepared on
     * it, or a closure bound to the client that owns it. Walks the class
     * hierarchy: getProperties() answers for one class at a time, and
     * these are private to the base.
     *
     * @return list<string>
     */
    private static function connectionReferencesOf(object $transaction): array
    {
        $held = [];

        for ($class = new ReflectionObject($transaction); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                $value = $property->getValue($transaction);

                if ($value instanceof PDO || $value instanceof PdoStatementCache || $value instanceof Closure) {
                    $held[] = $class->getShortName() . '::$' . $property->getName();
                }
            }
        }

        return $held;
    }

    /** @return array<string, mixed> */
    private static function statementMemoOf(FakePdoClient $client): array
    {
        return self::entriesOf(self::cacheOf($client));
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
