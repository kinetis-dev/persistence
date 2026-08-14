<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use Kinetis\Persistence\Driver\SqlParamInterpolator;
use Kinetis\Persistence\Exception\QueryException;
use PHPUnit\Framework\TestCase;

final class SqlParamInterpolatorTest extends TestCase
{
    /** Encodes ints verbatim and wraps everything else in <>, making substitutions visible. */
    private static function interpolate(string $sql, array $params): string
    {
        return SqlParamInterpolator::interpolate(
            $sql,
            $params,
            static fn (mixed $value): string => \is_int($value) ? (string) $value : '<' . $value . '>',
        );
    }

    public function test_substitutes_placeholders_positionally(): void
    {
        self::assertSame(
            'SELECT 1 WHERE a = 7 AND b = <x>',
            self::interpolate('SELECT 1 WHERE a = ? AND b = ?', [7, 'x']),
        );
    }

    public function test_question_marks_inside_quoted_strings_are_data_not_placeholders(): void
    {
        self::assertSame(
            "SELECT 'a?b' WHERE c = 1",
            self::interpolate("SELECT 'a?b' WHERE c = ?", [1]),
        );
        self::assertSame(
            'SELECT "a?b" WHERE c = 1',
            self::interpolate('SELECT "a?b" WHERE c = ?', [1]),
        );
        self::assertSame(
            'SELECT `weird?col` WHERE c = 1',
            self::interpolate('SELECT `weird?col` WHERE c = ?', [1]),
        );
    }

    public function test_backslash_escapes_inside_quotes_are_honored(): void
    {
        // The escaped quote must not close the string — the "?" after it
        // is still inside the literal.
        self::assertSame(
            "SELECT 'it\\'s?fine' WHERE c = 1",
            self::interpolate("SELECT 'it\\'s?fine' WHERE c = ?", [1]),
        );
    }

    public function test_backticks_have_no_backslash_escape(): void
    {
        // Inside backticks a backslash is a literal byte; the closing
        // backtick still closes, so the following "?" is a placeholder.
        self::assertSame(
            'SELECT `a\\` WHERE c = 1',
            self::interpolate('SELECT `a\\` WHERE c = ?', [1]),
        );
    }

    public function test_too_few_params_throws(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('more "?" placeholders than parameters');
        self::interpolate('SELECT ? + ?', [1]);
    }

    public function test_too_many_params_throws(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('has 1 "?" placeholders but 2 parameters were given');
        self::interpolate('SELECT ?', [1, 2]);
    }

    public function test_no_placeholders_and_no_params_passes_through(): void
    {
        self::assertSame('SELECT 1', self::interpolate('SELECT 1', []));
    }
}
