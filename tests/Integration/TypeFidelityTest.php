<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Cross-driver type fidelity: the same value, bound or read through any
 * of the four drivers, must come back with the same PHP type and exact
 * content. Every dialect-level difference that legitimately exists
 * (booleans are ints on MySQL and bools on Postgres) is pinned here as
 * *driver-consistent within its dialect*, so a divergence between the
 * async driver and the PDO fallback — the pair DB_DRIVER=auto swaps
 * between runtimes — can never reappear silently.
 */
final class TypeFidelityTest extends DriverCase
{
    #[DataProvider('drivers')]
    public function test_bigint_round_trips_exactly_beyond_float_precision(string $driver): void
    {
        $db = self::makeClient($driver);
        $cast = self::isMysql($driver) ? 'SIGNED' : 'BIGINT';

        $row = $db->execute("SELECT CAST(? AS {$cast}) AS v", [9007199254740993])->fetchRow();
        self::assertSame(9007199254740993, $row['v'] ?? null);

        $row = $db->query("SELECT CAST(9223372036854775807 AS {$cast}) AS v")->fetchRow();
        self::assertSame(\PHP_INT_MAX, $row['v'] ?? null);

        $row = $db->query("SELECT CAST(-9223372036854775808 AS {$cast}) AS v")->fetchRow();
        self::assertSame(\PHP_INT_MIN, $row['v'] ?? null);

        $db->close();
    }

    #[DataProvider('mysqlDrivers')]
    public function test_bigint_unsigned_beyond_php_int_max_stays_a_string(string $driver): void
    {
        $db = self::makeClient($driver);

        $row = $db->query('SELECT CAST(18446744073709551615 AS UNSIGNED) AS v')->fetchRow();
        // Unrepresentable as a PHP int — both MySQL drivers agree on the
        // exact string rather than a lossy float.
        self::assertSame('18446744073709551615', $row['v'] ?? null);

        $db->close();
    }

    #[DataProvider('drivers')]
    public function test_boolean_parameters_bind_identically(string $driver): void
    {
        $db = self::makeClient($driver);
        $sql = self::isMysql($driver)
            ? "SELECT IF(?, 'yes', 'no') AS v"
            : "SELECT CASE WHEN ?::boolean THEN 'yes' ELSE 'no' END AS v";

        self::assertSame('yes', $db->execute($sql, [true])->fetchRow()['v'] ?? null);
        self::assertSame('no', $db->execute($sql, [false])->fetchRow()['v'] ?? null);

        $db->close();
    }

    #[DataProvider('drivers')]
    public function test_boolean_parameters_bind_identically_inside_a_transaction(string $driver): void
    {
        $db = self::makeClient($driver);
        $sql = self::isMysql($driver)
            ? "SELECT IF(?, 'yes', 'no') AS v"
            : "SELECT CASE WHEN ?::boolean THEN 'yes' ELSE 'no' END AS v";

        $tx = $db->beginTransaction();
        self::assertSame('no', $tx->execute($sql, [false])->fetchRow()['v'] ?? null);
        $tx->rollback();

        $db->close();
    }

    #[DataProvider('drivers')]
    public function test_boolean_columns_read_consistently_within_each_dialect(string $driver): void
    {
        $db = self::makeClient($driver);

        $row = $db->query('SELECT TRUE AS t, FALSE AS f')->fetchRow();
        self::assertNotNull($row);

        if (self::isMysql($driver)) {
            // MySQL has no real boolean type — TRUE/FALSE are ints, on
            // both the async driver and the PDO fallback.
            self::assertSame(1, $row['t']);
            self::assertSame(0, $row['f']);
        } else {
            self::assertTrue($row['t']);
            self::assertFalse($row['f']);
        }

        $db->close();
    }

    #[DataProvider('drivers')]
    public function test_null_binds_in_every_position(string $driver): void
    {
        $db = self::makeClient($driver);

        $row = $db->execute('SELECT ? AS a, ? AS b, ? AS c', [null, 'x', null])->fetchRow();
        self::assertNotNull($row);
        self::assertNull($row['a']);
        self::assertSame('x', $row['b']);
        self::assertNull($row['c']);

        $db->close();
    }

    #[DataProvider('drivers')]
    public function test_empty_string_stays_distinct_from_null(string $driver): void
    {
        $db = self::makeClient($driver);

        $row = $db->execute('SELECT ? AS v', [''])->fetchRow();
        self::assertSame('', $row['v'] ?? null);

        $db->close();
    }

    #[DataProvider('drivers')]
    public function test_multibyte_and_quote_heavy_strings_round_trip_exactly(string $driver): void
    {
        $db = self::makeClient($driver);
        $value = "emoji \u{1F680} quote ' double \" backslash \\ pct % underscore _ nl\n end";

        $row = $db->execute('SELECT ? AS v', [$value])->fetchRow();
        self::assertSame($value, $row['v'] ?? null);

        $db->close();
    }

    #[DataProvider('drivers')]
    public function test_double_columns_read_as_native_floats(string $driver): void
    {
        $db = self::makeClient($driver);
        $cast = self::isMysql($driver) ? 'DOUBLE' : 'DOUBLE PRECISION';

        $row = $db->execute("SELECT CAST(? AS {$cast}) AS v", [1.0E-300])->fetchRow();
        self::assertSame(1.0E-300, $row['v'] ?? null);

        $db->close();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mysqlDrivers(): iterable
    {
        yield 'mysqli-async' => ['mysqli-async'];
        yield 'pdo-mysql' => ['pdo-mysql'];
    }

}
