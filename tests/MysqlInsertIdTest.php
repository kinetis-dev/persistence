<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use Kinetis\Persistence\Driver\MysqlInsertId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MysqlInsertIdTest extends TestCase
{
    /** Zero, false and null are all MySQL's "this statement generated no id". */
    #[DataProvider('noIdValues')]
    public function test_no_generated_id_is_null(int|string|false|null $raw): void
    {
        self::assertNull(MysqlInsertId::normalize($raw));
    }

    /** @return iterable<string, array{int|string|false|null}> */
    public static function noIdValues(): iterable
    {
        yield 'zero int (mysqli)' => [0];
        yield 'zero string (PDO)' => ['0'];
        yield 'null' => [null];
        yield 'false (PDO failure return)' => [false];
        yield 'empty string' => [''];
    }

    /** mysqli reports an int; PDO::lastInsertId() always reports a string. */
    #[DataProvider('inRangeValues')]
    public function test_a_value_within_php_int_range_is_an_int(int|string $raw, int $expected): void
    {
        self::assertSame($expected, MysqlInsertId::normalize($raw));
    }

    /** @return iterable<string, array{int|string, int}> */
    public static function inRangeValues(): iterable
    {
        yield 'small int' => [1, 1];
        yield 'small string' => ['1', 1];
        yield 'PHP_INT_MAX as int' => [\PHP_INT_MAX, \PHP_INT_MAX];
        yield 'PHP_INT_MAX as string' => [(string) \PHP_INT_MAX, \PHP_INT_MAX];
    }

    /**
     * An UNSIGNED BIGINT id past PHP_INT_MAX stays the string the driver
     * reported: an (int) cast saturates at PHP_INT_MAX and would name a
     * different row.
     */
    public function test_a_value_past_php_int_range_stays_a_string(): void
    {
        self::assertSame('9223372036854775808', MysqlInsertId::normalize('9223372036854775808'));
        self::assertSame('18446744073709551615', MysqlInsertId::normalize('18446744073709551615'));
    }
}
