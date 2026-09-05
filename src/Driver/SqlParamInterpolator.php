<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Kinetis\Persistence\Exception\QueryException;
use Closure;

/**
 * Rewrites the "?" positional placeholders Kinetis's query builder and
 * the SqlLink execute() contract use into whatever a native driver
 * needs: an escaped literal for mysqli (whose async mode has no
 * server-side bind step), or "$1".."$n" for pg_send_query_params.
 *
 * A dialect-aware scanner, not a generic one shared verbatim between
 * both drivers — MySQL and Postgres disagree on enough lexical detail
 * (backslash escaping, comment syntax, nested comments, dollar-quoted
 * strings) that a single quote-tracking pass over both was silently
 * miscounting "?" in valid SQL under real reproduction: inside line
 * comments (both "--" and MySQL's "#"), block comments, Postgres
 * $$...$$/$tag$...$tag$ strings, and standard (non-escape) Postgres
 * strings where a backslash is ordinary data, not an escape character.
 *
 * Placeholders are only recognized *outside* quoted regions, comments,
 * and dollar-quoted strings — a "?" inside any of those is data, not a
 * slot. "??" is a published escape for a literal, non-placeholder "?" —
 * needed for Postgres's own jsonb "?"/"?|"/"?&" operators, which are
 * lexically identical to a bind placeholder at the position they
 * appear; Doctrine DBAL uses the same doubling convention for the
 * identical reason.
 *
 * Two MySQL-specific rules beyond ordinary comment scanning, confirmed
 * against a real MySQL 8.4 server rather than assumed from the "--"/"/*"
 * syntax alone: a "--" only opens a comment when the second dash is
 * followed by whitespace, a control character, or the end of the string
 * — "5--?" is "5 - - ?" (two minus signs and a real placeholder), not a
 * comment, and MySQL's own parser agrees. Postgres has no such
 * condition; a bare "--" always opens a comment there. And "/*!...*\/"
 * (MySQL) / "/*M!...*\/" (MariaDB) are *executable* comments — the
 * server runs what's inside them, subject to its own version gating
 * against the connected server's actual version. Whether that gate is
 * satisfied can only be decided by asking the live connection, which
 * this client-side scanner never does — so a "?" inside one is rejected
 * outright rather than guessed at (see rejectPlaceholderInsideExecutableComment()),
 * on both the native and PDO drivers alike — the latter run the same
 * split for its count alone, before PDO ever sees the query. The
 * content itself, gate satisfied or not, is otherwise left untouched —
 * copied through verbatim for the connected server to interpret on its
 * own, exactly as it always has for a comment with no placeholder in it.
 *
 * Alongside the rewrite, this class holds the positional-parameter rules
 * every driver is bound by: list keying, exactly one argument per
 * recognized "?", and the value kinds a driver can put on the wire
 * ({@see assertPositionalKeys()}, {@see assertParameterCount()},
 * {@see assertBindableValues()}). {@see SqlParamPreflight} is what runs
 * them — in that order, and ahead of everything a driver does. The PDO
 * drivers rewrite nothing and are held to all three, which is what puts
 * callers of all four drivers on one contract.
 *
 * @internal
 */
final class SqlParamInterpolator
{
    /**
     * Rewrites a query the pre-flight has already settled, encoding each
     * accepted value into the fragment that replaces its "?" — the
     * native drivers' half of {@see SqlParamPreflight}'s outcome. The
     * PDO drivers rewrite nothing (a native prepare hands the query to
     * the server unchanged) and read the same outcome's values straight
     * into their binder.
     *
     * No check of its own: $query is already keyed, counted and typed,
     * which is what lets the encoder's arms be the five bindable kinds
     * and nothing else.
     *
     * @param Closure(null|bool|int|float|string, int): string $encode
     *     Receives one value and its zero-based position.
     */
    public static function render(PreflightedQuery $query, Closure $encode): string
    {
        $segments = $query->segments;
        $out = \array_shift($segments) ?? '';

        // One segment per placeholder is left, in placeholder order.
        foreach ($segments as $index => $segment) {
            $out .= $encode($query->values[$index], $index) . $segment;
        }

        return $out;
    }

    /**
     * The positional-arity rule every driver holds callers to: exactly
     * one argument per "?" {@see split()} recognized, no more and no
     * fewer. A driver accepting any other number would have to invent
     * a value or drop one — see {@see PdoStatementCache} for what
     * inventing one costs on a statement being reused.
     *
     * The diagnostic names the two counts and no parameter value: the
     * counts are what a caller needs to find the mistake, and the
     * values may be anything the query was carrying.
     */
    public static function assertParameterCount(int $placeholders, int $given, string $sql = ''): void
    {
        if ($placeholders === $given) {
            return;
        }

        throw new QueryException(\sprintf(
            'Query has %d "?" %s but %d %s given',
            $placeholders,
            $placeholders === 1 ? 'placeholder' : 'placeholders',
            $given,
            $given === 1 ? 'parameter was' : 'parameters were',
        ), $sql);
    }

    /**
     * The keying half of the same contract, asserted in one place so
     * the PDO and native drivers cannot read the same array
     * differently: $params is a list — keys 0..n-1, in the order the
     * "?" placeholders appear.
     *
     * A non-list means something different to each driver family.
     * {@see PdoParamBinder} binds by iteration order, so
     * ['b' => 2, 'a' => 1] puts 2 at position 1 and 1 at position 2;
     * {@see render()} indexes by position, so the same array
     * leaves every placeholder without a value. A gap in otherwise
     * numeric keys splits them the same way: [0 => 'x', 2 => 'y'] is
     * two ordered values to the binder and a hole at index 1 to the
     * interpolator.
     *
     * Reindexing with array_values() would make both agree on an
     * argument list the caller never wrote, which is the mistake worth
     * seeing rather than absorbing — so a non-list is rejected, ahead
     * of any prepare, interpolation or dispatch.
     *
     * @param array<array-key, mixed> $params
     */
    public static function assertPositionalKeys(array $params, string $sql = ''): void
    {
        if (\array_is_list($params)) {
            return;
        }

        throw new QueryException(
            'Query parameters must be a list keyed 0..n-1 in placeholder order; an associative '
            . 'or sparse array is rejected rather than reindexed, so a mis-keyed argument list '
            . 'surfaces at the call site instead of binding somewhere unintended.',
            $sql,
        );
    }

    /**
     * The value half of the same contract, and the last gate before a
     * driver commits to anything: every argument is one of the five
     * kinds Kinetis puts on the wire — null, bool, int, finite float,
     * string. The whole list is read before any of it is encoded,
     * bound or prepared, so a call carrying one unsupported value
     * leaves no statement prepared, no position bound and no row
     * changed.
     *
     * The narrower set is the one all four drivers agree on. PDO
     * binds anything else as PDO::PARAM_STR and casts it to a string
     * inside the bind loop, raising a PHP warning or an Error with the
     * earlier positions of a reused statement already bound, while the
     * native drivers reach the same value in their own encoder and
     * reject it. Deciding it here keeps every driver on one accepted
     * set and one diagnostic. Non-finite floats are rejected on the
     * same grounds: INF and NAN have no literal either dialect accepts,
     * and casting them yields "INF"/"NAN" for the server to fail on far
     * from the call site that bound them.
     *
     * A rejection names the position and the type, never the value.
     *
     * @param array<array-key, mixed> $params
     * @return list<null|bool|int|float|string> The same values, in the
     *     same order, typed to the contract they were just held to.
     */
    public static function assertBindableValues(array $params, string $sql = ''): array
    {
        $values = [];
        $index = 0;

        foreach ($params as $value) {
            if (\is_float($value) && !\is_finite($value)) {
                throw new QueryException(\sprintf(
                    'Parameter at index %d is a non-finite float; only a finite float can be bound.',
                    $index,
                ), $sql);
            }

            if ($value !== null && !\is_bool($value) && !\is_int($value) && !\is_float($value) && !\is_string($value)) {
                throw new QueryException(\sprintf(
                    'Parameter at index %d is of type %s; only null, bool, int, finite float and string can be bound.',
                    $index,
                    \get_debug_type($value),
                ), $sql);
            }

            $values[] = $value;
            $index++;
        }

        return $values;
    }

    /**
     * The one lexical pass over $sql every driver's pre-flight runs,
     * splitting it on each real placeholder in the order it appears and
     * copying everything else through verbatim. Splitting rather than
     * substituting is what lets the pre-flight finish before a single
     * value is looked up: the count falls out of the split, and the
     * segments are what {@see render()} later joins encoded values
     * between. Both driver families read the identical set of slots
     * because both read this one.
     *
     * Throws when $sql has no defensible set of slots at all — a "?"
     * inside a MySQL executable comment
     * ({@see rejectPlaceholderInsideExecutableComment()}), an
     * unterminated block comment or dollar-quoted string.
     *
     * @return list<string> The literal text between the placeholders,
     *     one segment more than there are placeholders.
     */
    public static function split(string $sql, SqlDialect $dialect): array
    {
        $segments = [];
        $current = '';
        $length = \strlen($sql);
        $quote = null;
        $quoteIsEscapeString = false;
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];

            if ($quote !== null) {
                [$consumed, $quote] = self::consumeQuotedChar(
                    $sql,
                    $i,
                    $char,
                    $quote,
                    $length,
                    $dialect,
                    $quoteIsEscapeString,
                );
                $current .= $consumed;
                $i += \strlen($consumed);
                continue;
            }

            $special = self::consumeNonQuotedSpecial($sql, $i, $length, $dialect);

            if ($special !== null) {
                [$consumed, $i] = $special;
                $current .= $consumed;
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                // Postgres's E'...' escape-string syntax is the one case
                // where a backslash inside a Postgres single-quoted
                // region *is* an escape character — everywhere else
                // there, per architecture decision, it's ordinary data.
                $quoteIsEscapeString = $dialect === SqlDialect::Postgres
                    && $char === "'"
                    && $i > 0
                    && ($sql[$i - 1] === 'E' || $sql[$i - 1] === 'e');
                $current .= $char;
                $i++;
                continue;
            }

            if ($char === '?') {
                // "??" is the published escape for a literal, non-slot
                // "?" — one character of ordinary text, not a split.
                if ($i + 1 < $length && $sql[$i + 1] === '?') {
                    $current .= '?';
                    $i += 2;
                    continue;
                }

                $segments[] = $current;
                $current = '';
                $i++;
                continue;
            }

            $current .= $char;
            $i++;
        }

        $segments[] = $current;

        return $segments;
    }

    /**
     * The current char while inside a quoted region, honoring a
     * backslash escape where the dialect actually gives backslash that
     * meaning: always for MySQL (except inside backticks, which have no
     * escape character beyond doubling — the closing quote just flips
     * state back on its own, which handles doubling correctly enough
     * for placeholder-scanning purposes), and only inside a Postgres
     * E'...' escape string.
     *
     * @return array{0: string, 1: ?string} The literal text consumed (one
     *     char, or two for a backslash escape) and the resulting quote
     *     state — null once the closing quote itself was consumed.
     */
    private static function consumeQuotedChar(
        string $sql,
        int $i,
        string $char,
        string $quote,
        int $length,
        SqlDialect $dialect,
        bool $isEscapeString,
    ): array {
        $backslashIsEscape = $quote !== '`' && ($dialect === SqlDialect::Mysql || $isEscapeString);

        if ($char === '\\' && $backslashIsEscape && $i + 1 < $length) {
            return [$char . $sql[$i + 1], $quote];
        }

        return [$char, $char === $quote ? null : $quote];
    }

    /**
     * MySQL only recognizes "--" as a comment opener when the second dash
     * is followed by whitespace, a control character, or nothing at all
     * (the "--" sits at the very end of the string) — confirmed against a
     * real MySQL 8.4 server, which parses "5--?" as "5 - - ?", not a
     * comment. $sql[$i] is the first "-" of the pair.
     */
    private static function mysqlDoubleDashIsComment(string $sql, int $i, int $length): bool
    {
        if ($i + 2 >= $length) {
            return true;
        }

        $next = $sql[$i + 2];

        return $next === ' ' || \ctype_cntrl($next);
    }

    /**
     * "/*!...*\/" (MySQL) and "/*M!...*\/" (MariaDB) are executable
     * comments — the server runs the content inside them (subject to its
     * own version-number gating, e.g. "/*!50000...*\/"), so they're not
     * inert the way an ordinary block comment is. $sql[$i] is the "/" of
     * the opening "/*".
     */
    private static function isExecutableComment(string $sql, int $i, int $length): bool
    {
        if ($i + 2 < $length && $sql[$i + 2] === '!') {
            return true;
        }

        return $i + 3 < $length && $sql[$i + 2] === 'M' && $sql[$i + 3] === '!';
    }

    /**
     * Throws if $content — an executable comment's own text, opening
     * "/*!"/"/*M!" and closing "*\/" both included — contains a "?" at
     * all, the published "??" literal escape deliberately not exempted
     * (see the reasoning inline below). Whether such a placeholder is
     * actually live depends on the connected server's own version (and,
     * for "/*M!", whether it's MariaDB at all), which neither native
     * driver knows without asking the connection — rather than risk the
     * native and PDO drivers silently disagreeing on how many bound
     * parameters a query needs depending on server version, Kinetis
     * narrows the supported grammar and rejects the combination outright,
     * identically everywhere. Doesn't track quotes or nested comments
     * inside $content: the combination this guards against is already
     * esoteric enough that erring toward rejecting an occurrence that
     * would, in fact, have been inert — inside a further quote or comment
     * nested within the executable comment itself — is an acceptable,
     * disclosed narrowing, not a correctness gap.
     */
    private static function rejectPlaceholderInsideExecutableComment(string $content): void
    {
        // The doubled "??" literal-escape convention is deliberately not
        // honored here, unlike everywhere else in this class: it has no
        // established meaning to a real server's own native placeholder
        // recognition either, so treating it as safe would just move the
        // exact ambiguity this method exists to close from one spelling
        // of "?" to another instead of actually closing it.
        if (\str_contains($content, '?')) {
            throw new QueryException(
                'A "?" placeholder cannot appear inside a version-gated executable comment '
                . '(/*!...*/ or /*M!...*/) — whether it is live depends on the connected '
                . 'server\'s own version, which the native and PDO drivers would resolve '
                . 'differently for the same query. Move the bound value outside the comment.',
            );
        }
    }

    /**
     * Tries to consume a comment or Postgres dollar-quoted span starting
     * at $sql[$i], outside any regular quote — the four constructs
     * {@see split()} has to recognize before falling through to
     * quote-open/placeholder/plain-character handling of its own.
     * Returns the literal text to copy through and the byte offset just
     * past it, or null when $sql[$i] doesn't actually open any of them.
     * Each construct gets its own try*() method below, tried in turn —
     * they're independent (never share state beyond the same $sql/$i),
     * unlike the mid-string scanners in this class, so splitting them
     * out names each one instead of leaving four early-return branches
     * folded into a single dispatcher.
     *
     * @return array{0: string, 1: int}|null
     */
    private static function consumeNonQuotedSpecial(string $sql, int $i, int $length, SqlDialect $dialect): ?array
    {
        return self::tryDoubleDashComment($sql, $i, $length, $dialect)
            ?? self::tryHashComment($sql, $i, $length, $dialect)
            ?? self::tryBlockComment($sql, $i, $length, $dialect)
            ?? self::tryDollarQuote($sql, $i, $length, $dialect);
    }

    /**
     * A "--" that {@see mysqlDoubleDashIsComment()} agrees opens a
     * comment (always true for Postgres, conditional for MySQL).
     *
     * @return array{0: string, 1: int}|null
     */
    private static function tryDoubleDashComment(string $sql, int $i, int $length, SqlDialect $dialect): ?array
    {
        if ($sql[$i] !== '-' || $i + 1 >= $length || $sql[$i + 1] !== '-') {
            return null;
        }

        $isComment = $dialect !== SqlDialect::Mysql || self::mysqlDoubleDashIsComment($sql, $i, $length);

        if (!$isComment) {
            return null;
        }

        $end = self::lineCommentEnd($sql, $i, $length);

        return [\substr($sql, $i, $end - $i), $end];
    }

    /**
     * MySQL's "#" line comment — Postgres has no equivalent.
     *
     * @return array{0: string, 1: int}|null
     */
    private static function tryHashComment(string $sql, int $i, int $length, SqlDialect $dialect): ?array
    {
        if ($dialect !== SqlDialect::Mysql || $sql[$i] !== '#') {
            return null;
        }

        $end = self::lineCommentEnd($sql, $i, $length);

        return [\substr($sql, $i, $end - $i), $end];
    }

    /**
     * A "/* ... *\/" block comment, both dialects — rejecting a "?"
     * inside it first when it's also an executable comment.
     *
     * @return array{0: string, 1: int}|null
     */
    private static function tryBlockComment(string $sql, int $i, int $length, SqlDialect $dialect): ?array
    {
        if ($sql[$i] !== '/' || $i + 1 >= $length || $sql[$i + 1] !== '*') {
            return null;
        }

        $isExecutable = $dialect === SqlDialect::Mysql && self::isExecutableComment($sql, $i, $length);
        $end = self::blockCommentEnd($sql, $i, $length, $dialect);
        $content = \substr($sql, $i, $end - $i);

        if ($isExecutable) {
            self::rejectPlaceholderInsideExecutableComment($content);
        }

        return [$content, $end];
    }

    /**
     * A Postgres "$$"/"$tag$" dollar-quoted string — no equivalent on
     * MySQL, where "$" is always ordinary text.
     *
     * @return array{0: string, 1: int}|null
     */
    private static function tryDollarQuote(string $sql, int $i, int $length, SqlDialect $dialect): ?array
    {
        if ($dialect !== SqlDialect::Postgres || $sql[$i] !== '$') {
            return null;
        }

        $delimiter = self::dollarQuoteDelimiter($sql, $i, $length);

        if ($delimiter === null) {
            return null;
        }

        $end = self::dollarQuoteEnd($sql, $i, $delimiter, $length);

        return [\substr($sql, $i, $end - $i), $end];
    }

    /** $sql[$start] is the first "-" of a "--" (or the "#") that opens the comment. */
    private static function lineCommentEnd(string $sql, int $start, int $length): int
    {
        $newline = \strpos($sql, "\n", $start);

        return $newline === false ? $length : $newline;
    }

    /**
     * $sql[$start] is the "/" of the opening "/*". Postgres nests block
     * comments per its own documentation; MySQL does not — a "/*"
     * encountered while already inside one doesn't open a second level,
     * so the very next "*\/" closes it regardless of depth.
     */
    private static function blockCommentEnd(string $sql, int $start, int $length, SqlDialect $dialect): int
    {
        $depth = 1;
        $i = $start + 2;

        while ($i < $length && $depth > 0) {
            if ($dialect === SqlDialect::Postgres && $sql[$i] === '/' && $i + 1 < $length && $sql[$i + 1] === '*') {
                $depth++;
                $i += 2;
                continue;
            }

            if ($sql[$i] === '*' && $i + 1 < $length && $sql[$i + 1] === '/') {
                $depth--;
                $i += 2;
                continue;
            }

            $i++;
        }

        if ($depth > 0) {
            throw new QueryException("Unterminated block comment starting at byte {$start}");
        }

        return $i;
    }

    /**
     * Matches a Postgres dollar-quote opening delimiter — "$$" or
     * "$tag$" — starting at $sql[$i], which must already be "$".
     * Returns the full delimiter text or null when $i doesn't actually
     * start one: a bare "$" used as ordinary, non-quoting text, a tag
     * this grammar doesn't match, or a "$" that belongs to the
     * identifier on its left ({@see dollarOpensQuote()}).
     *
     * A tag is spelled with PostgreSQL's unquoted-identifier bytes
     * ({@see isIdentifierStartByte()}, {@see isTagByte()}), so "$é$" is
     * a delimiter as much as "$body$" is. Because the closing delimiter
     * is the opening one's exact bytes, that single rule also fixes
     * where the quoted region ends.
     */
    private static function dollarQuoteDelimiter(string $sql, int $i, int $length): ?string
    {
        if (!self::dollarOpensQuote($sql, $i)) {
            return null;
        }

        $j = $i + 1;

        if ($j < $length && $sql[$j] === '$') {
            return '$$';
        }

        if ($j >= $length || !self::isIdentifierStartByte($sql[$j])) {
            return null;
        }

        $j++;

        while ($j < $length && self::isTagByte($sql[$j])) {
            $j++;
        }

        if ($j >= $length || $sql[$j] !== '$') {
            return null;
        }

        return \substr($sql, $i, $j - $i + 1);
    }

    /**
     * Whether the "$" at $sql[$i] can open a dollar-quoted string at
     * all, or belongs to the identifier immediately to its left.
     * PostgreSQL accepts "$" inside an identifier after its first byte
     * and lexes the longest match available, so "col$tag$" is one
     * identifier named col$tag$ — a dollar-quoted literal following an
     * identifier or a keyword has to be separated from it, and only
     * "col $tag$" opens one. Reading the attached form as an opener
     * both rejects valid SQL, when nothing closes the delimiter it
     * invents, and lets two such fragments bracket a real "?" and hide
     * it.
     *
     * A run of identifier bytes is an identifier only when it starts
     * with a byte an identifier may start with: a letter, "_", or a
     * byte at or above \x80, which is PostgreSQL's own byte-level rule
     * for everything non-ASCII. A run starting with a digit is a
     * numeric literal, which "$" does not continue — "1$tag$" is the
     * integer 1 followed by a real dollar quote.
     *
     * It is the same byte set {@see dollarQuoteDelimiter()} spells a
     * tag with, minus the "$" that ends a tag instead of continuing it
     * — one rule deciding both which "$" can open a quote and what may
     * follow it.
     */
    private static function dollarOpensQuote(string $sql, int $i): bool
    {
        $start = $i;

        while ($start > 0 && self::isIdentifierByte($sql[$start - 1])) {
            $start--;
        }

        return $start === $i || !self::isIdentifierStartByte($sql[$start]);
    }

    /** A byte PostgreSQL accepts as an identifier's first. */
    private static function isIdentifierStartByte(string $byte): bool
    {
        return ($byte >= 'a' && $byte <= 'z')
            || ($byte >= 'A' && $byte <= 'Z')
            || $byte === '_'
            || $byte >= "\x80";
    }

    /** A byte PostgreSQL accepts anywhere in an identifier past the first. */
    private static function isIdentifierByte(string $byte): bool
    {
        return self::isIdentifierStartByte($byte)
            || ($byte >= '0' && $byte <= '9')
            || $byte === '$';
    }

    /**
     * A byte a dollar-quote tag carries past its first: an identifier
     * byte other than "$", which closes the tag rather than continuing
     * it. "$a$b$" therefore opens on the tag "a", exactly as the server
     * lexes it.
     */
    private static function isTagByte(string $byte): bool
    {
        return $byte !== '$' && self::isIdentifierByte($byte);
    }

    /**
     * $sql[$start] is the opening delimiter's first "$"; returns the
     * byte offset just past the matching closing delimiter. Nothing
     * inside a dollar-quoted string is special — not even a backslash —
     * only the exact same delimiter closes it.
     */
    private static function dollarQuoteEnd(string $sql, int $start, string $delimiter, int $length): int
    {
        $closeAt = \strpos($sql, $delimiter, $start + \strlen($delimiter));

        if ($closeAt === false) {
            throw new QueryException("Unterminated dollar-quoted string starting at byte {$start}");
        }

        return \min($closeAt + \strlen($delimiter), $length);
    }
}
