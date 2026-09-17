<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Kinetis\Persistence\Exception\QueryException;

/**
 * The one lexical pass every driver's pre-flight runs over a query's SQL,
 * splitting it on each real "?" placeholder in the order it appears and
 * copying everything else through verbatim.
 *
 * The scan is dialect-aware because MySQL and Postgres disagree on enough
 * lexical detail — backslash escaping, comment syntax, nested comments,
 * dollar-quoted strings — that which "?" is a placeholder is a dialect
 * question. Placeholders are recognized only *outside* quoted regions,
 * comments and dollar-quoted strings; a "?" inside any of them is data.
 * "??" is {@see SqlParamInterpolator}'s published escape for a literal,
 * non-placeholder "?", which Postgres's own jsonb "?"/"?|"/"?&" operators
 * need, being lexically identical to a bind placeholder where they appear.
 *
 * Two rules are MySQL's rather than a generic reading of the syntax. A
 * "--" only opens a comment when the second dash is followed by
 * whitespace, a control character, or the end of the string: "5--?" is
 * "5 - - ?", a real placeholder. Postgres has no such condition. And a
 * backslash escapes inside a MySQL quoted region but is ordinary data
 * inside a standard Postgres one, where only E'...' gives it that meaning.
 *
 * @internal
 */
final class SqlPlaceholderScanner
{
    /**
     * Splitting rather than substituting is what lets the pre-flight
     * finish before a single value is looked up: the placeholder count
     * falls out of the split, and the segments are what
     * {@see SqlParamInterpolator::render()} later joins encoded values
     * between. Both driver families read the identical set of slots
     * because both read this one scan.
     *
     * Throws when $sql has no defensible set of slots at all — an
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
     * A "/* ... *\/" block comment, both dialects. MySQL's version-gated
     * executable comments ("/*!...*\/", "/*M!...*\/") are comments here
     * like any other: their contents are copied through for the
     * connected server to interpret, and a "?" inside one is not a slot.
     *
     * @return array{0: string, 1: int}|null
     */
    private static function tryBlockComment(string $sql, int $i, int $length, SqlDialect $dialect): ?array
    {
        if ($sql[$i] !== '/' || $i + 1 >= $length || $sql[$i + 1] !== '*') {
            return null;
        }

        $end = self::blockCommentEnd($sql, $i, $length, $dialect);

        return [\substr($sql, $i, $end - $i), $end];
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
