<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Integration;

use Kinetis\Persistence\Contract\PrefersPreparedStatements;
use Kinetis\Persistence\Driver\PdoStatementCache;
use Kinetis\Persistence\Exception\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionObject;

/**
 * The PDO drivers memoize prepared statements, and say so by carrying
 * PrefersPreparedStatements — which is what tells a caller such as
 * kinetis/query-builder to bind a value rather than write it into the
 * SQL. The two have to stay in step: a driver that stopped reusing
 * statements while still carrying the marker would send callers down the
 * slower path of the two.
 *
 * Reuse is asserted against the cache itself rather than a timing, which
 * is the only way to observe it deterministically — repeating a statement
 * is faster, but not by an amount a test can depend on.
 */
final class PreparedStatementReuseTest extends DriverCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function pdoDrivers(): iterable
    {
        yield 'pdo-mysql' => ['pdo-mysql'];
        yield 'pdo-pgsql' => ['pdo-pgsql'];
    }

    #[DataProvider('drivers')]
    public function test_only_the_pdo_drivers_declare_a_preference_for_binding(string $driver): void
    {
        $db = self::makeClient($driver);

        self::assertSame(
            \str_starts_with($driver, 'pdo-'),
            $db instanceof PrefersPreparedStatements,
            "{$driver} carries the wrong PrefersPreparedStatements marker",
        );

        $db->close();
    }

    #[DataProvider('pdoDrivers')]
    public function test_a_repeated_statement_is_prepared_once_on_a_connection(string $driver): void
    {
        $db = self::makeClient($driver);

        for ($i = 1; $i <= 5; $i++) {
            // Loose: an untyped ? comes back as text on Postgres, which
            // TypeFidelityTest covers. What matters here is the cache.
            self::assertEquals($i, $db->execute('SELECT ? AS v', [$i])->fetchRow()['v'] ?? null);
        }

        self::assertSame(1, self::statementCacheSize($db), 'one SQL string, one prepare');

        $db->execute('SELECT ? AS other', [1])->fetchRow();
        self::assertSame(2, self::statementCacheSize($db), 'a different SQL string is its own entry');

        $db->close();
    }

    /**
     * A reused statement still holds what was last bound to it, so the
     * argument count is checked on every execution rather than only when
     * the statement is first prepared: the second call below would
     * otherwise run against the first call's leftover second value.
     */
    #[DataProvider('pdoDrivers')]
    public function test_a_reused_statement_holds_its_caller_to_the_placeholder_count(string $driver): void
    {
        $db = self::makeClient($driver);

        self::assertEquals(2, $db->execute('SELECT ? AS a, ? AS b', [1, 2])->fetchRow()['b'] ?? null);

        foreach ([[3], [1, 2, 3]] as $mismatched) {
            try {
                $db->execute('SELECT ? AS a, ? AS b', $mismatched);
                self::fail("Expected {$driver} to reject " . \count($mismatched) . ' parameters.');
            } catch (QueryException $e) {
                self::assertSame(
                    \sprintf(
                        'Query has 2 "?" placeholders but %d %s given',
                        \count($mismatched),
                        \count($mismatched) === 1 ? 'parameter was' : 'parameters were',
                    ),
                    $e->getMessage(),
                );
            }
        }

        // The rejections leave the connection and its cache usable.
        self::assertEquals(5, $db->execute('SELECT ? AS a, ? AS b', [4, 5])->fetchRow()['b'] ?? null);
        self::assertSame(1, self::statementCacheSize($db));

        $db->close();
    }

    /** The transaction's own cache is held to the identical rule. */
    #[DataProvider('pdoDrivers')]
    public function test_a_transaction_holds_its_caller_to_the_placeholder_count_too(string $driver): void
    {
        $db = self::makeClient($driver);
        $tx = $db->beginTransaction();

        self::assertEquals(2, $tx->execute('SELECT ? AS a, ? AS b', [1, 2])->fetchRow()['b'] ?? null);

        try {
            $tx->execute('SELECT ? AS a, ? AS b', [3]);
            self::fail("Expected {$driver} to reject 1 parameter inside a transaction.");
        } catch (QueryException $e) {
            self::assertSame('Query has 2 "?" placeholders but 1 parameter was given', $e->getMessage());
        }

        self::assertEquals(5, $tx->execute('SELECT ? AS a, ? AS b', [4, 5])->fetchRow()['b'] ?? null);
        self::assertSame(1, self::statementCacheSize($tx));

        $tx->rollback();
        $db->close();
    }

    /**
     * A transaction owns its own PDO handle for its lifetime, so it keeps
     * its own cache. Without one it would prepare on every call, and
     * binding — the path the marker sends callers down — would cost two
     * round trips per query instead of one.
     */
    #[DataProvider('pdoDrivers')]
    public function test_a_transaction_reuses_statements_too(string $driver): void
    {
        $db = self::makeClient($driver);
        $tx = $db->beginTransaction();

        self::assertInstanceOf(PrefersPreparedStatements::class, $tx);

        for ($i = 1; $i <= 5; $i++) {
            self::assertEquals($i, $tx->execute('SELECT ? AS v', [$i])->fetchRow()['v'] ?? null);
        }

        self::assertSame(1, self::statementCacheSize($tx));

        $tx->rollback();
        $db->close();
    }

    /** How many distinct SQL strings $target currently holds prepared. */
    private static function statementCacheSize(object $target): int
    {
        // Walked rather than read straight off the object: the cache is
        // private on PdoTransaction, and reflection over a concrete
        // subclass does not expose a parent's private property.
        for ($class = new ReflectionObject($target); $class !== false; $class = $class->getParentClass()) {
            if ($class->hasProperty('statements')) {
                $cache = $class->getProperty('statements')->getValue($target);
                self::assertInstanceOf(PdoStatementCache::class, $cache);
                $entries = new ReflectionObject($cache)->getProperty('entries')->getValue($cache);
                self::assertIsArray($entries);

                return \count($entries);
            }
        }

        self::fail($target::class . ' has no statement cache');
    }
}
