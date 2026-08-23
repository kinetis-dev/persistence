<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Integration;

use Kinetis\Persistence\Contract\PrefersPreparedStatements;
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

        self::assertCount(1, self::statementCache($db), 'one SQL string, one prepare');

        $db->execute('SELECT ? AS other', [1])->fetchRow();
        self::assertCount(2, self::statementCache($db), 'a different SQL string is its own entry');

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

        self::assertCount(1, self::statementCache($tx));

        $tx->rollback();
        $db->close();
    }

    /**
     * @return array<string, mixed>
     */
    private static function statementCache(object $target): array
    {
        // Walked rather than read straight off the object: the cache is
        // private on PdoTransaction, and reflection over a concrete
        // subclass does not expose a parent's private property.
        for ($class = new ReflectionObject($target); $class !== false; $class = $class->getParentClass()) {
            if ($class->hasProperty('statements')) {
                /** @var array<string, mixed> */
                return $class->getProperty('statements')->getValue($target);
            }
        }

        self::fail($target::class . ' has no statement cache');
    }
}
