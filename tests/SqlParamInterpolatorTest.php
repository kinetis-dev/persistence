<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use Kinetis\Persistence\Driver\SqlDialect;
use Kinetis\Persistence\Driver\SqlParamInterpolator;
use Kinetis\Persistence\Driver\SqlParamPreflight;
use Kinetis\Persistence\Exception\QueryException;
use Kinetis\Persistence\Tests\Fixtures\StringableParameter;
use PHPUnit\Framework\TestCase;
use stdClass;

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

    /** The exact diagnostic assertPositionalKeys() throws. */
    private const string NON_LIST_PARAMS_MESSAGE = 'Query parameters must be a list keyed 0..n-1 in '
        . 'placeholder order; an associative or sparse array is rejected rather than reindexed, so a '
        . 'mis-keyed argument list surfaces at the call site instead of binding somewhere unintended.';

    /**
     * The whole pre-flight and the rewrite behind it, the way a native
     * driver runs the pair: ints are encoded verbatim and everything
     * else is wrapped in <>, making substitutions visible.
     *
     * @param array<array-key, mixed> $params
     */
    private static function interpolate(string $sql, array $params, SqlDialect $dialect = SqlDialect::Mysql): string
    {
        return SqlParamInterpolator::render(
            new SqlParamPreflight($dialect)->run($sql, $params),
            static fn (mixed $value): string => \is_int($value) ? (string) $value : '<' . $value . '>',
        );
    }

    /** How many "?" the scan recognizes as placeholders in $sql. */
    private static function placeholders(string $sql, SqlDialect $dialect): int
    {
        return \count(SqlParamInterpolator::split($sql, $dialect)) - 1;
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
        // The same count diagnostic a longer list gets, and the same one
        // the PDO drivers reach through the same split: the pre-flight
        // settles before any value is looked up, so a short list reads
        // identically on every driver.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Query has 2 "?" placeholders but 1 parameter was given');
        self::interpolate('SELECT ? + ?', [1]);
    }

    /**
     * The interpolating drivers substitute values as they scan, so
     * "refused" has to mean refused before the first substitution —
     * otherwise a short list reads differently here than on the PDO
     * drivers, which never substitute at all.
     */
    public function test_a_short_argument_list_is_rejected_before_any_value_is_encoded(): void
    {
        $encoded = [];
        $encode = static function (null|bool|int|float|string $value) use (&$encoded): string {
            $encoded[] = $value;

            return 'x';
        };

        foreach ([SqlDialect::Mysql, SqlDialect::Postgres] as $dialect) {
            try {
                SqlParamInterpolator::render(
                    new SqlParamPreflight($dialect)->run('SELECT ?, ?, ?', ['a']),
                    $encode,
                );
                self::fail('Expected the argument count to be rejected.');
            } catch (QueryException $e) {
                self::assertSame('Query has 3 "?" placeholders but 1 parameter was given', $e->getMessage());
            }
        }

        self::assertSame([], $encoded, 'A rejected call encoded a parameter value.');
    }

    public function test_only_null_bool_int_finite_float_and_string_can_be_bound(): void
    {
        $accepted = [null, true, false, 0, -7, \PHP_INT_MAX, 1.5, -0.5, \PHP_FLOAT_MAX, '', 'x'];

        self::assertSame($accepted, SqlParamInterpolator::assertBindableValues($accepted, 'SELECT 1'));
        self::assertSame([], SqlParamInterpolator::assertBindableValues([], 'SELECT 1'));
    }

    public function test_an_unbindable_value_names_its_position_and_type_and_no_value(): void
    {
        $stream = \fopen('php://memory', 'r');
        self::assertIsResource($stream);

        $cases = [
            'array' => [[1, 2], 'Parameter at index 1 is of type array; only null, bool, int, finite float and string can be bound.'],
            'object' => [new stdClass(), 'Parameter at index 1 is of type stdClass; only null, bool, int, finite float and string can be bound.'],
            // A Stringable is refused with every other object — the
            // one kind the four drivers would otherwise disagree about.
            // Format it at the call site instead.
            'stringable' => [new StringableParameter(), 'Parameter at index 1 is of type ' . StringableParameter::class . '; only null, bool, int, finite float and string can be bound.'],
            'closure' => [static fn (): int => 1, 'Parameter at index 1 is of type Closure; only null, bool, int, finite float and string can be bound.'],
            'resource' => [$stream, 'Parameter at index 1 is of type resource (stream); only null, bool, int, finite float and string can be bound.'],
            'INF' => [\INF, 'Parameter at index 1 is a non-finite float; only a finite float can be bound.'],
            '-INF' => [-\INF, 'Parameter at index 1 is a non-finite float; only a finite float can be bound.'],
            'NAN' => [\NAN, 'Parameter at index 1 is a non-finite float; only a finite float can be bound.'],
        ];

        foreach ($cases as $label => [$value, $message]) {
            try {
                SqlParamInterpolator::assertBindableValues(['fine', $value, 'also fine'], 'SELECT ?, ?, ?');
                self::fail("Expected {$label} to be rejected.");
            } catch (QueryException $e) {
                self::assertSame($message, $e->getMessage(), $label);
            }
        }

        \fclose($stream);
    }

    /**
     * The two checks are ordered, so a call that is both short and
     * carrying an unsupported value reads the same on every driver
     * rather than depending on which one looked first.
     */
    public function test_the_argument_count_is_settled_before_any_value_is_examined(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Query has 2 "?" placeholders but 1 parameter was given');

        self::interpolate('SELECT ?, ?', [[1, 2]], SqlDialect::Postgres);
    }

    public function test_an_unsupported_value_is_rejected_before_any_value_is_encoded(): void
    {
        $encoded = [];
        $encode = static function (null|bool|int|float|string $value) use (&$encoded): string {
            $encoded[] = $value;

            return 'x';
        };

        foreach ([SqlDialect::Mysql, SqlDialect::Postgres] as $dialect) {
            try {
                SqlParamInterpolator::render(
                    new SqlParamPreflight($dialect)->run('SELECT ?, ?', ['fine', \NAN]),
                    $encode,
                );
                self::fail('Expected the non-finite float to be rejected.');
            } catch (QueryException $e) {
                self::assertSame('Parameter at index 1 is a non-finite float; only a finite float can be bound.', $e->getMessage());
            }
        }

        // Not even the accepted value at position 0 was encoded: the
        // whole list is read before any of it is committed to.
        self::assertSame([], $encoded, 'A rejected call encoded a parameter value.');
    }

    public function test_too_many_params_throws(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('has 1 "?" placeholder but 2 parameters were given');
        self::interpolate('SELECT ?', [1, 2]);
    }

    public function test_an_associative_parameter_array_is_rejected_rather_than_reindexed(): void
    {
        // A PDO driver binds these in iteration order and the
        // interpolator by position -- one call, two different queries.
        // array_values() here would settle that on an argument list the
        // caller never wrote, so the keys are rejected instead.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(self::NON_LIST_PARAMS_MESSAGE);

        self::interpolate('SELECT ?, ?', ['a' => 1, 'b' => 2]);
    }

    public function test_sparse_and_reordered_numeric_parameter_keys_are_rejected_too(): void
    {
        // A gap and a swapped pair are the same mistake as a string key:
        // the position a value binds at stops being the key it carries.
        foreach ([[0 => 1, 2 => 2], [1 => 1, 0 => 2]] as $params) {
            try {
                self::interpolate('SELECT ?, ?', $params);
                self::fail('Expected the parameter keys to be rejected.');
            } catch (QueryException $e) {
                self::assertSame(self::NON_LIST_PARAMS_MESSAGE, $e->getMessage());
            }
        }
    }

    public function test_the_keying_assertion_carries_the_sql_and_passes_every_list(): void
    {
        try {
            SqlParamInterpolator::assertPositionalKeys(['id' => 1], 'SELECT ?');
            self::fail('Expected a non-list to be rejected.');
        } catch (QueryException $e) {
            self::assertSame(self::NON_LIST_PARAMS_MESSAGE, $e->getMessage());
            self::assertSame('SELECT ?', $e->getQuery());
        }

        // A list returns without throwing, the empty one included.
        SqlParamInterpolator::assertPositionalKeys([]);
        SqlParamInterpolator::assertPositionalKeys([1, 'two', null]);
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

    public function test_a_dollar_quote_does_not_open_where_an_identifier_continues_into_it(): void
    {
        // Postgres allows "$" inside an identifier past its first byte
        // and lexes the longest match, so "column$tag$" is a single
        // identifier -- there is no delimiter here to leave unterminated,
        // and the real placeholder after it still counts.
        self::assertSame(
            'SELECT column$tag$ FROM t WHERE c = 1',
            self::interpolate('SELECT column$tag$ FROM t WHERE c = ?', [1], SqlDialect::Postgres),
        );

        // The same rule stops two such fragments from bracketing a real
        // placeholder and swallowing it: neither "$" opens anything, so
        // the "?" between them is a slot, not string contents.
        self::assertSame(
            'SELECT a$x$, 1 AS v, b$x$ FROM t',
            self::interpolate('SELECT a$x$, ? AS v, b$x$ FROM t', [1], SqlDialect::Postgres),
        );

        // "$$" attaches to the identifier on its left the same way, and
        // an identifier may start with "_" as much as with a letter.
        self::assertSame(
            'SELECT col$$ AS v, _x$$ AS w WHERE c = 1',
            self::interpolate('SELECT col$$ AS v, _x$$ AS w WHERE c = ?', [1], SqlDialect::Postgres),
        );
    }

    public function test_a_dollar_quote_after_a_real_token_boundary_still_opens(): void
    {
        // Separated from the identifier, the delimiter is real again
        // and the "?" inside it is data.
        self::assertSame(
            'SELECT column $tag$ ? $tag$ AS v WHERE c = 1',
            self::interpolate('SELECT column $tag$ ? $tag$ AS v WHERE c = ?', [1], SqlDialect::Postgres),
        );

        // A digit run is a numeric literal rather than an identifier, and
        // "$" does not continue one -- "1$tag$" is the integer 1 followed
        // by a real dollar quote.
        self::assertSame(
            'SELECT 1$tag$ ? $tag$ AS v WHERE c = 1',
            self::interpolate('SELECT 1$tag$ ? $tag$ AS v WHERE c = ?', [1], SqlDialect::Postgres),
        );

        // Punctuation is a boundary exactly like whitespace.
        self::assertSame(
            'SELECT ($tag$ ? $tag$) AS v WHERE c = 1',
            self::interpolate('SELECT ($tag$ ? $tag$) AS v WHERE c = ?', [1], SqlDialect::Postgres),
        );
    }

    public function test_identifier_bytes_above_ascii_end_the_boundary_too(): void
    {
        // Postgres states its identifier rule in bytes: everything at or
        // above \x80 may start or continue one, so a multi-byte letter
        // holds the "$" inside the identifier just as an ASCII one does.
        self::assertSame(
            'SELECT café$tag$ FROM t WHERE c = 1',
            self::interpolate('SELECT café$tag$ FROM t WHERE c = ?', [1], SqlDialect::Postgres),
        );

        self::assertSame(
            'SELECT ünique$tag$ FROM t WHERE c = 1',
            self::interpolate('SELECT ünique$tag$ FROM t WHERE c = ?', [1], SqlDialect::Postgres),
        );
    }

    public function test_a_dollar_quote_tag_may_carry_non_ascii_identifier_bytes(): void
    {
        // PostgreSQL spells a dollar-quote tag with its own unquoted
        // identifier rule, which it states in bytes: everything at or
        // above \x80 may start or continue one. "$é$" therefore quotes
        // its contents exactly as "$body$" does, and the "?" inside is
        // data while the one outside is still a slot.
        self::assertSame(
            'SELECT $é$ ? $é$ AS v WHERE c = 1',
            self::interpolate('SELECT $é$ ? $é$ AS v WHERE c = ?', [1], SqlDialect::Postgres),
        );

        // A tag mixing ASCII and non-ASCII bytes is the same rule.
        self::assertSame(
            'SELECT $aé$ ? $aé$ AS v WHERE c = 1',
            self::interpolate('SELECT $aé$ ? $aé$ AS v WHERE c = ?', [1], SqlDialect::Postgres),
        );
    }

    public function test_mysql_has_no_dollar_quoting_so_the_same_text_holds_two_placeholders(): void
    {
        // "$" is ordinary text on MySQL, so neither fragment quotes
        // anything and both "?" are slots -- the dialect gate, not the
        // tag grammar, is what separates the two readings.
        self::assertSame(
            'SELECT $é$ 1 $é$ AS v WHERE c = 2',
            self::interpolate('SELECT $é$ ? $é$ AS v WHERE c = ?', [1, 2]),
        );
    }

    public function test_an_unterminated_non_ascii_dollar_quote_throws(): void
    {
        // Recognizing the opener is what makes this unterminated at all:
        // a scanner that read "$é$" as ordinary text would count the "?"
        // and return.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Unterminated dollar-quoted string');
        self::interpolate('SELECT $é$ never closed ?', [1], SqlDialect::Postgres);
    }

    public function test_a_dollar_quote_delimiter_does_not_overmatch(): void
    {
        // A longer tag is not the shorter one: "$tagx$" holds no "$tag$",
        // so only the final "$tag$" closes the region and both "?" inside
        // it are data.
        self::assertSame(
            'SELECT $tag$ ? $tagx$ ? $tag$ AS v WHERE c = 1',
            self::interpolate('SELECT $tag$ ? $tagx$ ? $tag$ AS v WHERE c = ?', [1], SqlDialect::Postgres),
        );

        // "$" is an identifier byte but not a tag byte -- it closes a tag
        // rather than continuing it -- so "$a$" opens here and the second
        // "$a$" closes it.
        self::assertSame(
            'SELECT $a$?$a$ AS v WHERE c = 1',
            self::interpolate('SELECT $a$?$a$ AS v WHERE c = ?', [1], SqlDialect::Postgres),
        );

        // A "$" no tag closes opens nothing: "$1" is Postgres's own
        // positional spelling, ordinary text to this scanner, and the
        // "?" after it is still a slot.
        self::assertSame(
            'SELECT $1, 1 AS v',
            self::interpolate('SELECT $1, ? AS v', [1], SqlDialect::Postgres),
        );

        // Neither does a bare "$" before a boundary.
        self::assertSame(
            'SELECT $ || 1 AS v',
            self::interpolate('SELECT $ || ? AS v', [1], SqlDialect::Postgres),
        );

        // Nor a "$" whose would-be tag runs to the end of the string.
        self::assertSame(
            'SELECT 1 AS v, $tag',
            self::interpolate('SELECT ? AS v, $tag', [1], SqlDialect::Postgres),
        );
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

    public function test_counting_placeholders_agrees_with_what_interpolation_substitutes(): void
    {
        // The PDO drivers never interpolate; they count instead, and the
        // count has to name the same slots interpolation would have
        // replaced, or the two driver families would hold callers to
        // different argument lists for the same query.
        $cases = [
            [SqlDialect::Mysql, 'SELECT ? + ?', 2],
            [SqlDialect::Mysql, "SELECT 'a?b' WHERE c = ?", 1],
            [SqlDialect::Mysql, 'SELECT `weird?col` WHERE c = ?', 1],
            [SqlDialect::Mysql, "SELECT 1 -- ?\nWHERE c = ?", 1],
            [SqlDialect::Mysql, "SELECT 1 # ?\nWHERE c = ?", 1],
            [SqlDialect::Mysql, 'SELECT /* ? */ ? AS v', 1],
            [SqlDialect::Mysql, 'SELECT 5--? AS n', 1],
            [SqlDialect::Mysql, "SELECT 'a\\' WHERE c = ?", 0],
            [SqlDialect::Postgres, 'SELECT 5--? AS n', 0],
            [SqlDialect::Postgres, "SELECT 'a\\' WHERE c = ?", 1],
            [SqlDialect::Postgres, "SELECT E'it\\'s?fine' WHERE c = ?", 1],
            [SqlDialect::Postgres, 'SELECT $$what about ?$$ WHERE c = ?', 1],
            [SqlDialect::Postgres, 'SELECT $body$has $other$ and ? inside$body$ WHERE c = ?', 1],
            [SqlDialect::Postgres, 'SELECT column$tag$ FROM t WHERE c = ?', 1],
            [SqlDialect::Postgres, 'SELECT a$x$, ? AS v, b$x$ FROM t', 1],
            [SqlDialect::Postgres, 'SELECT 1$tag$ ? $tag$ AS v WHERE c = ?', 1],
            [SqlDialect::Postgres, 'SELECT $é$ ? $é$ AS v WHERE c = ?', 1],
            [SqlDialect::Postgres, 'SELECT $aé$ ? $aé$ AS v WHERE c = ?', 1],
            [SqlDialect::Postgres, 'SELECT $tag$ ? $tagx$ ? $tag$ AS v WHERE c = ?', 1],
            [SqlDialect::Postgres, 'SELECT $a$?$a$ AS v WHERE c = ?', 1],
            [SqlDialect::Postgres, 'SELECT $1, ? AS v', 1],
            [SqlDialect::Postgres, 'SELECT ? AS v, $tag', 1],
            [SqlDialect::Mysql, 'SELECT $é$ ? $é$ AS v WHERE c = ?', 2],
            [SqlDialect::Postgres, "SELECT 1 WHERE data ??| array['a'] AND c = ?", 1],
            [SqlDialect::Postgres, 'SELECT /*! ? + */ 1 AS n', 0],
        ];

        foreach ($cases as [$dialect, $sql, $expected]) {
            self::assertSame($expected, self::placeholders($sql, $dialect), $sql);

            // Interpolating the same SQL with exactly that many
            // parameters is the cross-check: a disagreement in either
            // direction throws instead of returning.
            self::interpolate($sql, \array_fill(0, $expected, 1), $dialect);
        }
    }

    public function test_the_split_rejects_a_placeholder_inside_an_executable_comment(): void
    {
        // The split is the one pass both driver families run, so a query
        // with no defensible parameter count never gets one on either.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(self::EXECUTABLE_COMMENT_PLACEHOLDER_MESSAGE);

        SqlParamInterpolator::split('SELECT /*!50000 ? */ 1 AS n', SqlDialect::Mysql);
    }

    public function test_asserting_the_parameter_count_names_both_counts_and_no_value(): void
    {
        // Diagnostics are the two counts and nothing else: the values
        // themselves may be anything the query was carrying. Each count
        // reads in its own grammatical number, so neither half of the
        // message ever says "1 placeholders" or "1 parameters".
        $cases = [
            [1, 0, 'Query has 1 "?" placeholder but 0 parameters were given'],
            [1, 2, 'Query has 1 "?" placeholder but 2 parameters were given'],
            [2, 1, 'Query has 2 "?" placeholders but 1 parameter was given'],
            [3, 2, 'Query has 3 "?" placeholders but 2 parameters were given'],
        ];

        foreach ($cases as [$placeholders, $given, $message]) {
            try {
                SqlParamInterpolator::assertParameterCount($placeholders, $given, 'SELECT ?');
                self::fail('Expected a mismatched count to be rejected.');
            } catch (QueryException $e) {
                self::assertSame($message, $e->getMessage());
                self::assertSame('SELECT ?', $e->getQuery());
            }
        }

        // A matching count returns without throwing, which is all it
        // has to do.
        SqlParamInterpolator::assertParameterCount(2, 2, 'SELECT ?, ?');
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
