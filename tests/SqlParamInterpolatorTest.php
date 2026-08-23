<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use Kinetis\Persistence\Driver\SqlDialect;
use Kinetis\Persistence\Driver\SqlParamInterpolator;
use Kinetis\Persistence\Exception\QueryException;
use PHPUnit\Framework\TestCase;

final class SqlParamInterpolatorTest extends TestCase
{
    /**
     * The exact, stable diagnostic rejectPlaceholderInsideExecutableComment()
     * throws — asserted in full below, not just a middle substring, so a
     * regression in either closing delimiter's own spelling ("/*!...*\/",
     * not the truncated "/*!.../ " this message once actually shipped
     * with) would fail a test rather than survive silently.
     */
    private const string EXECUTABLE_COMMENT_PLACEHOLDER_MESSAGE = 'A "?" placeholder cannot appear inside a '
        . 'version-gated executable comment (/*!...*/ or /*M!...*/) — whether it is live depends on the '
        . 'connected server\'s own version, which the native and PDO drivers would resolve differently for '
        . 'the same query. Move the bound value outside the comment.';

    /** Encodes ints verbatim and wraps everything else in <>, making substitutions visible. */
    private static function interpolate(string $sql, array $params, SqlDialect $dialect = SqlDialect::Mysql): string
    {
        return SqlParamInterpolator::interpolate(
            $sql,
            $params,
            static fn (mixed $value): string => \is_int($value) ? (string) $value : '<' . $value . '>',
            $dialect,
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

    public function test_question_marks_inside_line_comments_are_not_placeholders(): void
    {
        self::assertSame(
            "SELECT 1 -- what about ?\nWHERE c = 1",
            self::interpolate("SELECT 1 -- what about ?\nWHERE c = ?", [1]),
        );
    }

    public function test_hash_starts_a_line_comment_only_for_mysql(): void
    {
        self::assertSame(
            "SELECT 1 # what about ?\nWHERE c = 1",
            self::interpolate("SELECT 1 # what about ?\nWHERE c = ?", [1], SqlDialect::Mysql),
        );

        // Postgres has no "#" comment syntax at all -- the "?" right
        // after it is real, unquoted SQL and needs a real parameter.
        self::assertSame(
            "SELECT 1 # what about <x>\nWHERE c = 1",
            self::interpolate("SELECT 1 # what about ?\nWHERE c = ?", ['x', 1], SqlDialect::Postgres),
        );
    }

    public function test_question_marks_inside_block_comments_are_not_placeholders(): void
    {
        self::assertSame(
            'SELECT 1 /* what about ? */ WHERE c = 1',
            self::interpolate('SELECT 1 /* what about ? */ WHERE c = ?', [1]),
        );
    }

    public function test_block_comments_nest_only_for_postgres(): void
    {
        // The "?" sits between the inner comment's own close and the
        // outer one's -- MySQL never nests, so its comment ends at the
        // *first* "*/" (right after "inner"), leaving " ? */ WHERE c = ?"
        // as real SQL: two genuine placeholders, one of them that
        // now-stray "?".
        self::assertSame(
            'SELECT 1 /* outer /* inner */ 2 */ WHERE c = 1',
            self::interpolate('SELECT 1 /* outer /* inner */ ? */ WHERE c = ?', [2, 1], SqlDialect::Mysql),
        );

        // Postgres nests per its own documentation: the middle "?" stays
        // inside the still-open outer comment, so only the trailing "?"
        // in the WHERE clause is a real placeholder.
        self::assertSame(
            'SELECT 1 /* outer /* inner */ ? */ WHERE c = 1',
            self::interpolate('SELECT 1 /* outer /* inner */ ? */ WHERE c = ?', [1], SqlDialect::Postgres),
        );
    }

    public function test_mysql_double_dash_is_only_a_comment_when_the_second_dash_is_followed_by_whitespace(): void
    {
        // A real MySQL 8.4 server parses this as "5 - - 2", not a
        // comment -- the second dash here is immediately followed by
        // "?", not whitespace/a control character.
        self::assertSame(
            'SELECT 5--2 AS n',
            self::interpolate('SELECT 5--? AS n', [2], SqlDialect::Mysql),
        );

        // A "--" at the very end of the string (no third character at
        // all) still opens a comment -- there's nothing after it to
        // fail the whitespace condition against.
        self::assertSame(
            'SELECT 1 --',
            self::interpolate('SELECT 1 --', [], SqlDialect::Mysql),
        );
    }

    public function test_postgres_double_dash_is_always_a_comment_regardless_of_what_follows(): void
    {
        // Postgres has no MySQL-style whitespace condition on "--" -- a
        // bare "--?" always opens a comment there, commenting out the
        // rest of the line including what would otherwise be a
        // placeholder.
        self::assertSame(
            'SELECT 5--? AS n',
            self::interpolate('SELECT 5--? AS n', [], SqlDialect::Postgres),
        );
    }

    public function test_a_placeholder_inside_a_mysql_executable_comment_is_rejected(): void
    {
        // Whether "/*! ... */"'s content is even live SQL depends on the
        // connected server's own version -- something this client-side
        // scanner has no way to check. Rather than silently require a
        // different bound-parameter count than PDO's native prepare
        // would (which defers the same decision to the real server),
        // Kinetis rejects a "?" here outright, on both drivers alike.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(self::EXECUTABLE_COMMENT_PLACEHOLDER_MESSAGE);

        self::interpolate('SELECT /*! ? + */ 1 AS n', [2], SqlDialect::Mysql);
    }

    public function test_a_placeholder_inside_a_mariadb_executable_comment_is_rejected(): void
    {
        // MariaDB's own "/*M! ... */" variant is rejected the same way.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(self::EXECUTABLE_COMMENT_PLACEHOLDER_MESSAGE);

        self::interpolate('SELECT /*M! ? + */ 1 AS n', [2], SqlDialect::Mysql);
    }

    public function test_a_placeholder_inside_a_version_numbered_executable_comment_is_rejected(): void
    {
        // The version-numbered form of either syntax behaves identically
        // -- the number itself is never inspected.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(self::EXECUTABLE_COMMENT_PLACEHOLDER_MESSAGE);

        self::interpolate('SELECT /*!50000 ? + */ 1 AS n', [2], SqlDialect::Mysql);
    }

    public function test_a_mysql_executable_comment_with_no_placeholder_inside_it_is_left_untouched(): void
    {
        // The rejection above is scoped precisely to a genuine "?" --
        // an executable comment with none inside it (optimizer hints,
        // DEFINER clauses, and the like) is still copied through
        // verbatim, exactly as it always has been, for the connected
        // server to interpret on its own.
        self::assertSame(
            'SELECT /*!50000 STRAIGHT_JOIN */ 1 AS n',
            self::interpolate('SELECT /*!50000 STRAIGHT_JOIN */ 1 AS n', [], SqlDialect::Mysql),
        );
    }

    public function test_a_doubled_question_mark_inside_an_executable_comment_is_rejected_too(): void
    {
        // "??" is the published literal-escape for "?" everywhere else
        // in this grammar, but it has no established meaning to a real
        // server's own native placeholder recognition -- so it isn't
        // treated as safe here either, deliberately: doing so would
        // just move the same ambiguity to a different spelling of "?"
        // instead of closing it.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(self::EXECUTABLE_COMMENT_PLACEHOLDER_MESSAGE);

        self::interpolate('SELECT /*!50000 a ?? b */ 1 AS n', [], SqlDialect::Mysql);
    }

    public function test_an_executable_comment_marker_at_the_very_end_of_the_string_is_still_recognized(): void
    {
        // The "!" is the string's own last byte -- the exact boundary
        // isExecutableComment()'s "$i + 2 < $length" check has to get
        // right: one byte less and there'd be nothing at that offset to
        // read at all. With nothing left in the string to close it,
        // it's correctly recognized as executable and then correctly
        // reported as unterminated, the same as any other unclosed
        // block comment -- never silently passed through unchanged.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Unterminated block comment');

        self::interpolate('SELECT /*!', [], SqlDialect::Mysql);
    }

    public function test_an_unterminated_plain_block_comment_one_byte_shorter_still_throws(): void
    {
        // The same length, minus the "!" -- an ordinary, non-executable
        // block comment that's missing its closing "*/" entirely, which
        // must still be caught as unterminated rather than silently
        // treated as executable.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Unterminated block comment');

        self::interpolate('SELECT /*', [], SqlDialect::Mysql);
    }

    public function test_postgres_has_no_executable_comment_syntax(): void
    {
        // Postgres has no version-gated comment convention -- "/*! ... */"
        // is an ordinary, inert block comment there, exactly like any
        // other "/* ... */".
        self::assertSame(
            'SELECT /*! ? + */ 1 AS n',
            self::interpolate('SELECT /*! ? + */ 1 AS n', [], SqlDialect::Postgres),
        );
    }

    public function test_unterminated_block_comment_throws(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Unterminated block comment');
        self::interpolate('SELECT 1 /* never closed', []);
    }

    public function test_postgres_dollar_quoted_strings_are_not_scanned_for_placeholders(): void
    {
        self::assertSame(
            'SELECT $$what about ?$$ WHERE c = 1',
            self::interpolate('SELECT $$what about ?$$ WHERE c = ?', [1], SqlDialect::Postgres),
        );
    }

    public function test_postgres_tagged_dollar_quoting_requires_the_exact_same_tag_to_close(): void
    {
        // A different tag's own dollar signs inside the region don't
        // close it -- only $body$ matches $body$.
        self::assertSame(
            'SELECT $body$has $other$ and ? inside$body$ WHERE c = 1',
            self::interpolate('SELECT $body$has $other$ and ? inside$body$ WHERE c = ?', [1], SqlDialect::Postgres),
        );
    }

    public function test_unterminated_dollar_quote_throws(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Unterminated dollar-quoted string');
        self::interpolate('SELECT $tag$never closed', [], SqlDialect::Postgres);
    }

    public function test_doubled_question_mark_is_a_literal_not_a_placeholder(): void
    {
        // The published escape for Postgres's own jsonb ?/?|/?& operators,
        // lexically identical to a bind placeholder at that position.
        self::assertSame(
            'SELECT 1 WHERE data ? 1',
            self::interpolate('SELECT 1 WHERE data ?? 1', [], SqlDialect::Postgres),
        );
        self::assertSame(
            "SELECT 1 WHERE data ?| array['a'] AND c = 1",
            self::interpolate("SELECT 1 WHERE data ??| array['a'] AND c = ?", [1], SqlDialect::Postgres),
        );
        self::assertSame(
            "SELECT 1 WHERE data ?& array['a'] AND c = 1",
            self::interpolate("SELECT 1 WHERE data ??& array['a'] AND c = ?", [1], SqlDialect::Postgres),
        );
    }

    public function test_postgres_standard_strings_treat_backslash_as_ordinary_data(): void
    {
        // A trailing "\" immediately before the closing quote: MySQL's
        // backslash escapes that quote, so the string never actually
        // closes here -- the rest of the text, including its own "?",
        // stays inside it, with zero real placeholders to bind.
        self::assertSame(
            "SELECT 'a\\' WHERE c = ?",
            self::interpolate("SELECT 'a\\' WHERE c = ?", [], SqlDialect::Mysql),
        );

        // Postgres never treats backslash as a quote escape in a
        // standard string: the closing quote genuinely closes right
        // there, so the "?" that follows is real, unquoted SQL and
        // needs a real parameter.
        self::assertSame(
            "SELECT 'a\\' WHERE c = 1",
            self::interpolate("SELECT 'a\\' WHERE c = ?", [1], SqlDialect::Postgres),
        );
    }

    public function test_postgres_escape_strings_do_treat_backslash_as_an_escape(): void
    {
        // The E'...' prefix opts back into C-style backslash escaping,
        // unlike a standard Postgres string -- the "?" after the escaped
        // quote is still inside the literal, exactly like MySQL.
        self::assertSame(
            "SELECT E'it\\'s?fine' WHERE c = 1",
            self::interpolate("SELECT E'it\\'s?fine' WHERE c = ?", [1], SqlDialect::Postgres),
        );
    }
}
