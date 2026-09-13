<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use Kinetis\Persistence\Exception\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryExceptionTest extends TestCase
{
    public function test_the_vendor_code_and_sqlstate_are_carried_as_reported(): void
    {
        $e = new QueryException("Duplicate entry 'a' for key 'slug'", 'INSERT INTO t (slug) VALUES (?)', null, 1062, '23000');

        self::assertSame(1062, $e->getCode());
        self::assertSame('23000', $e->getSqlState());
        self::assertSame('INSERT INTO t (slug) VALUES (?)', $e->getQuery());
    }

    public function test_a_failure_that_reported_nothing_has_no_sqlstate_and_no_classification(): void
    {
        $e = new QueryException('Query failed', 'SELECT 1');

        self::assertSame(0, $e->getCode());
        self::assertNull($e->getSqlState());
        self::assertFalse($e->isUniqueViolation());
    }

    #[DataProvider('uniqueViolations')]
    public function test_a_unique_violation_is_classified(int $vendorCode, ?string $sqlState): void
    {
        self::assertTrue(new QueryException('conflict', '', null, $vendorCode, $sqlState)->isUniqueViolation());
    }

    /** @return iterable<string, array{int, ?string}> */
    public static function uniqueViolations(): iterable
    {
        yield 'PostgreSQL, native driver (no vendor code)' => [0, '23505'];
        yield 'PostgreSQL, PDO (libpq result status as the code)' => [7, '23505'];
        yield 'MySQL and MariaDB duplicate entry' => [1062, '23000'];
    }

    /**
     * SQLSTATE 23000 is the MySQL family's answer for every integrity
     * violation, so it classifies nothing on its own.
     */
    #[DataProvider('otherFailures')]
    public function test_any_other_failure_is_not_a_unique_violation(int $vendorCode, ?string $sqlState): void
    {
        self::assertFalse(new QueryException('failure', '', null, $vendorCode, $sqlState)->isUniqueViolation());
    }

    /** @return iterable<string, array{int, ?string}> */
    public static function otherFailures(): iterable
    {
        yield 'PostgreSQL NOT NULL violation' => [0, '23502'];
        yield 'PostgreSQL foreign key violation' => [7, '23503'];
        yield 'MySQL NOT NULL violation' => [1048, '23000'];
        yield 'MySQL foreign key violation' => [1452, '23000'];
        yield 'MySQL deadlock' => [1213, '40001'];
    }
}
