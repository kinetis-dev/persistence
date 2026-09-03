<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use Kinetis\Persistence\Driver\MysqlInsertId;
use Kinetis\Persistence\Exception\UnexpectedInsertIdException;
use PHPUnit\Framework\TestCase;

final class MysqlInsertIdTest extends TestCase
{
    public function test_zero_int_means_no_generated_id(): void
    {
        self::assertNull(MysqlInsertId::normalize(0));
    }

    public function test_zero_string_means_no_generated_id(): void
    {
        self::assertNull(MysqlInsertId::normalize('0'));
    }

    /** Canonicalization collapses an all-zero string of any length to the same "no id" meaning the bare "0" already carries. */
    public function test_a_longer_all_zero_string_means_no_generated_id(): void
    {
        self::assertNull(MysqlInsertId::normalize('00000'));
    }

    public function test_null_means_no_generated_id(): void
    {
        self::assertNull(MysqlInsertId::normalize(null));
    }

    /** PDO::lastInsertId()'s historically-documented failure return. */
    public function test_false_means_no_generated_id(): void
    {
        self::assertNull(MysqlInsertId::normalize(false));
    }

    public function test_empty_string_means_no_generated_id(): void
    {
        self::assertNull(MysqlInsertId::normalize(''));
    }

    /** mysqli's own $insert_id reports a value within range as a native int, not a string. */
    public function test_a_small_int_is_returned_as_int(): void
    {
        self::assertSame(1, MysqlInsertId::normalize(1));
    }

    /** PDO::lastInsertId() always reports as a string, even for a value well within int range. */
    public function test_a_small_numeric_string_is_returned_as_int(): void
    {
        self::assertSame(1, MysqlInsertId::normalize('1'));
    }

    /** Leading zeros on a small value, well below the PHP_INT_MAX boundary — must canonicalize to the same int, not be treated as exceeding range purely by character count. */
    public function test_a_small_leading_zero_string_is_returned_as_the_canonical_int(): void
    {
        self::assertSame(1, MysqlInsertId::normalize('0001'));
    }

    public function test_php_int_max_as_int_is_returned_as_int(): void
    {
        self::assertSame(\PHP_INT_MAX, MysqlInsertId::normalize(\PHP_INT_MAX));
    }

    public function test_php_int_max_as_string_is_returned_as_int(): void
    {
        self::assertSame(\PHP_INT_MAX, MysqlInsertId::normalize((string) \PHP_INT_MAX));
    }

    /** Leading zeros on the exact PHP_INT_MAX boundary itself — must still canonicalize to a real int, not fall through to string preservation because of the extra leading characters. */
    public function test_php_int_max_with_a_leading_zero_is_returned_as_int(): void
    {
        self::assertSame(\PHP_INT_MAX, MysqlInsertId::normalize('0' . \PHP_INT_MAX));
    }

    /**
     * The exact boundary this class exists to get right: one past
     * PHP_INT_MAX must never be lossily cast (which would silently
     * saturate to PHP_INT_MAX itself, confirmed directly before this
     * class was built) — it must survive as its own canonical decimal
     * string instead.
     */
    public function test_php_int_max_plus_one_is_preserved_as_a_string(): void
    {
        self::assertSame('9223372036854775808', MysqlInsertId::normalize('9223372036854775808'));
    }

    /** The same boundary, padded with leading zeros — the preserved string must still be canonical (no leading zeros), not the raw padded form. */
    public function test_php_int_max_plus_one_with_leading_zeros_is_preserved_canonically(): void
    {
        self::assertSame('9223372036854775808', MysqlInsertId::normalize('0009223372036854775808'));
    }

    /** MySQL's real UNSIGNED BIGINT ceiling — well past PHP_INT_MAX, more digits than it too. */
    public function test_mysql_unsigned_bigint_max_is_preserved_as_a_string(): void
    {
        self::assertSame('18446744073709551615', MysqlInsertId::normalize('18446744073709551615'));
    }

    /** Same ceiling value, leading-zero padded — the canonical (unpadded) string is what must come back. */
    public function test_mysql_unsigned_bigint_max_with_a_leading_zero_is_preserved_canonically(): void
    {
        self::assertSame('18446744073709551615', MysqlInsertId::normalize('018446744073709551615'));
    }

    /**
     * Never observed from a real mysqli/PDO MySQL backend, but not
     * assumed impossible — rejected loudly rather than silently passed
     * through as if it were a real id: SqlResult::getLastInsertId()'s
     * own contract promises only ever an int or a canonical decimal
     * string, and a value like "abc" is neither, so returning it
     * unchanged would push a broken backend invariant into application
     * code disguised as a valid generated id. The exception message is
     * a fixed diagnostic — proven here not to contain the raw input at
     * all, since driver-reported data of unknown shape has no business
     * in a log line.
     */
    public function test_a_non_numeric_string_is_rejected(): void
    {
        try {
            MysqlInsertId::normalize('abc');
            self::fail('Expected UnexpectedInsertIdException.');
        } catch (UnexpectedInsertIdException $e) {
            self::assertStringNotContainsString('abc', $e->getMessage());
        }
    }

    /** A negative numeric string should never come from a real UNSIGNED BIGINT id — rejected, not silently coerced or passed through. */
    public function test_a_negative_numeric_string_is_rejected(): void
    {
        $this->expectException(UnexpectedInsertIdException::class);

        MysqlInsertId::normalize('-5');
    }

    /**
     * mysqli's own $insert_id is the one call site that can hand this
     * class a negative int directly (a string always goes through the
     * digit regex above, which already rejects a leading "-"). Left
     * unchecked, the int branch's own "!== 0" test would let a negative
     * value straight through — a real inconsistency with the string
     * case, closed by rejecting it here too.
     */
    public function test_a_negative_int_is_rejected(): void
    {
        $this->expectException(UnexpectedInsertIdException::class);

        MysqlInsertId::normalize(-5);
    }

    /**
     * This helper only ever normalizes a value that is documented to
     * have come from a MySQL AUTO_INCREMENT column, whose widest
     * possible type is UNSIGNED BIGINT — nothing past that ceiling can
     * be a real generated id, however it was reported, so it is
     * rejected rather than preserved as if it were merely "a large
     * value."
     */
    public function test_one_past_mysql_unsigned_bigint_max_is_rejected(): void
    {
        $this->expectException(UnexpectedInsertIdException::class);

        MysqlInsertId::normalize('18446744073709551616');
    }

    /** The same ceiling-plus-one value, leading-zero padded — canonicalization must not accidentally bring it back into range. */
    public function test_one_past_mysql_unsigned_bigint_max_with_a_leading_zero_is_rejected(): void
    {
        $this->expectException(UnexpectedInsertIdException::class);

        MysqlInsertId::normalize('018446744073709551616');
    }

    /** An arbitrarily long digit string, far past any real column's range — must reject, not silently preserve. */
    public function test_a_very_long_digit_string_is_rejected(): void
    {
        $this->expectException(UnexpectedInsertIdException::class);

        MysqlInsertId::normalize(str_repeat('9', 50));
    }
}
