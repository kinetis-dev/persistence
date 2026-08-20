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
 * on both the native and PDO drivers alike (the latter via
 * assertNoExecutableCommentPlaceholder(), called from
 * PdoExecutionTrait::execute() before PDO ever sees the query). The
 * content itself, gate satisfied or not, is otherwise left untouched —
 * copied through verbatim for the connected server to interpret on its
 * own, exactly as it always has for a comment with no placeholder in it.
 *
 * @internal
 */
final class SqlParamInterpolator
{
    /**
     * @param list<mixed> $params
     * @param Closure(mixed, int): string $encode Encodes one parameter
     *     value into the exact SQL fragment replacing its "?". Receives
     *     the value and its zero-based position.
     */
    public static function interpolate(string $sql, array $params, Closure $encode, SqlDialect $dialect): string
    {
        $out = '';
        $paramIndex = 0;
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
                $out .= $consumed;
                $i += \strlen($consumed);
                continue;
            }

            $special = self::consumeNonQuotedSpecial($sql, $i, $length, $dialect);

            if ($special !== null) {
                [$consumed, $i] = $special;
                $out .= $consumed;
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
                $out .= $char;
                $i++;
                continue;
            }

            if ($char === '?') {
                if ($i + 1 < $length && $sql[$i + 1] === '?') {
                    $out .= '?';
                    $i += 2;
                    continue;
                }

                $out .= self::encodePlaceholder($params, $paramIndex, $encode);
                $paramIndex++;
                $i++;
                continue;
            }

            $out .= $char;
            $i++;
        }

        if ($paramIndex !== \count($params)) {
            throw new QueryException(\sprintf(
                'Query has %d "?" placeholders but %d parameters were given',
                $paramIndex,
                \count($params),
            ));
        }

        return $out;
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
     * {@see interpolate()} and {@see assertNoExecutableCommentPlaceholder()}
     * both have to recognize identically before falling through to
     * quote-open/placeholder/plain-character handling of their own.
     * Returns the literal text to copy through and the byte offset just
     * past it, or null when $sql[$i] doesn't actually open any of them.
     *
     * @return array{0: string, 1: int}|null
     */
    private static function consumeNonQuotedSpecial(string $sql, int $i, int $length, SqlDialect $dialect): ?array
    {
        $char = $sql[$i];

        if ($char === '-' && $i + 1 < $length && $sql[$i + 1] === '-') {
            $isComment = $dialect !== SqlDialect::Mysql || self::mysqlDoubleDashIsComment($sql, $i, $length);

            if ($isComment) {
                $end = self::lineCommentEnd($sql, $i, $length);

                return [\substr($sql, $i, $end - $i), $end];
            }
        }

        if ($dialect === SqlDialect::Mysql && $char === '#') {
            $end = self::lineCommentEnd($sql, $i, $length);

            return [\substr($sql, $i, $end - $i), $end];
        }

        if ($char === '/' && $i + 1 < $length && $sql[$i + 1] === '*') {
            $isExecutable = $dialect === SqlDialect::Mysql && self::isExecutableComment($sql, $i, $length);
            $end = self::blockCommentEnd($sql, $i, $length, $dialect);
            $content = \substr($sql, $i, $end - $i);

            if ($isExecutable) {
                self::rejectPlaceholderInsideExecutableComment($content);
            }

            return [$content, $end];
        }

        if ($dialect === SqlDialect::Postgres && $char === '$') {
            $delimiter = self::dollarQuoteDelimiter($sql, $i, $length);

            if ($delimiter !== null) {
                $end = self::dollarQuoteEnd($sql, $i, $delimiter, $length);

                return [\substr($sql, $i, $end - $i), $end];
            }
        }

        return null;
    }

    /**
     * The PDO drivers' own pre-flight equivalent of the check
     * {@see interpolate()} performs inline for the native drivers —
     * PDO never routes through interpolate() at all (native prepares
     * hand the query to the server unchanged), so without this, a query
     * containing a "?" inside an executable comment would silently
     * behave differently there than {@see rejectPlaceholderInsideExecutableComment()}
     * already makes it behave on the native drivers. Called from
     * {@see PdoExecutionTrait::execute()} before PDO::prepare() ever
     * sees the query. A no-op for Postgres, which has no executable-
     * comment syntax at all.
     */
    public static function assertNoExecutableCommentPlaceholder(string $sql, SqlDialect $dialect): void
    {
        if ($dialect !== SqlDialect::Mysql) {
            return;
        }

        $length = \strlen($sql);
        $quote = null;
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];

            if ($quote !== null) {
                [$consumed, $quote] = self::consumeQuotedChar($sql, $i, $char, $quote, $length, $dialect, false);
                $i += \strlen($consumed);
                continue;
            }

            $special = self::consumeNonQuotedSpecial($sql, $i, $length, $dialect);

            if ($special !== null) {
                $i = $special[1];
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $i++;
                continue;
            }

            $i++;
        }
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
     * "$tag$", where tag is [A-Za-z_][A-Za-z0-9_]* (ASCII only — a
     * disclosed, deliberate scope limit; every real migration/function
     * body this project has ever generated or reviewed uses a plain
     * ASCII tag) — starting at $sql[$i], which must already be "$".
     * Returns the full delimiter text or null when $i doesn't actually
     * start one, e.g. a bare "$" used as ordinary, non-quoting text.
     */
    private static function dollarQuoteDelimiter(string $sql, int $i, int $length): ?string
    {
        $j = $i + 1;

        if ($j < $length && $sql[$j] === '$') {
            return '$$';
        }

        if ($j >= $length || !(($sql[$j] >= 'A' && $sql[$j] <= 'Z') || ($sql[$j] >= 'a' && $sql[$j] <= 'z') || $sql[$j] === '_')) {
            return null;
        }

        $j++;

        while ($j < $length && (\ctype_alnum($sql[$j]) || $sql[$j] === '_')) {
            $j++;
        }

        if ($j >= $length || $sql[$j] !== '$') {
            return null;
        }

        return \substr($sql, $i, $j - $i + 1);
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

    /**
     * @param list<mixed> $params
     */
    private static function encodePlaceholder(array $params, int $paramIndex, Closure $encode): string
    {
        if (!\array_key_exists($paramIndex, $params)) {
            throw new QueryException('Query has more "?" placeholders than parameters');
        }

        return $encode($params[$paramIndex], $paramIndex);
    }
}
