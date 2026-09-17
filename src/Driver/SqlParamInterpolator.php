<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Kinetis\Persistence\Exception\QueryException;
use Closure;

/**
 * Rewrites the "?" positional placeholders Kinetis's query builder and
 * the SqlLink execute() contract use into whatever a native driver
 * needs: an escaped literal for mysqli (whose async mode has no
 * server-side bind step), or "$1".."$n" for pg_send_query_params. Which
 * "?" is a placeholder — as opposed to data inside a quoted region, a
 * comment, or a Postgres dollar-quoted string — is decided once, ahead
 * of any rewrite, by {@see SqlPlaceholderScanner::split()}. "??" is
 * execute()'s published escape for a literal, non-placeholder "?", which
 * that scan resolves the same way; query() takes complete SQL and never
 * reaches here, so a "?" in a query() string is never doubled.
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
     * The one float encoding every driver puts on the wire: numeric text
     * that reads back as the exact binary float it was given, on
     * MySQL/MariaDB and PostgreSQL alike.
     *
     * A `(string)` cast cannot do that. PHP's float-to-string cast is
     * governed by the `precision` INI setting — 14 significant digits by
     * default — so `0.1 + 0.2` casts to `0.3` and the microsecond
     * timestamp `1726480000.123456` to `1726480000.1235`: the value the
     * caller bound and the value the server stores are different
     * numbers. `json_encode()`, `var_export()` and `serialize()` only
     * move the same truncation onto `serialize_precision`. Neither
     * setting is this package's to change: it runs in persistent
     * workers, where an `ini_set()` outlives the request that made it
     * and changes how every other library in the process formats a
     * float.
     *
     * 17 significant digits is what makes any binary64 round-trip
     * exactly, and `%G` drops the trailing zeros that leaves on the
     * values needing fewer — `1.0` stays `1`, `1726480000.123456` stays
     * itself. What printf gives up in exchange is locale independence:
     * it spells the decimal separator the way `LC_NUMERIC` does, and a
     * comma would turn one bound value into two SQL expressions. The
     * separator is read from the locale and normalized back to `.`
     * here; the locale itself is never touched, for the same reason the
     * INI settings are not.
     *
     * Only a finite float reaches this. INF and NAN have no literal
     * either dialect accepts and are refused by
     * {@see assertBindableValues()}, ahead of every driver.
     */
    public static function encodeFloat(float $value): string
    {
        $text = \sprintf('%.17G', $value);
        // printf and localeconv() read the same LC_NUMERIC decimal
        // point, so this is exactly what $text carries in place of ".".
        $point = \localeconv()['decimal_point'];

        return $point === '.' ? $text : \str_replace($point, '.', $text);
    }

    /**
     * The positional-arity rule every driver holds callers to: exactly
     * one argument per "?" {@see SqlPlaceholderScanner::split()}
     * recognized, no more and no fewer. A driver accepting any other
     * number would have to invent a value or drop one — see
     * {@see PdoStatementCache} for what inventing one costs on a
     * statement being reused.
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
     * One rule is dialect-scoped: Postgres carries text parameters as C
     * strings, so a string holding a NUL byte would reach the server
     * truncated at that byte. MySQL transmits it intact — escaped by
     * real_escape_string, bound as binary by PDO — and VARBINARY/BLOB
     * columns legitimately hold one.
     *
     * A rejection names the position and the type, never the value.
     *
     * @param array<array-key, mixed> $params
     * @return list<null|bool|int|float|string> The same values, in the
     *     same order, typed to the contract they were just held to.
     */
    public static function assertBindableValues(array $params, SqlDialect $dialect, string $sql = ''): array
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

            if ($dialect === SqlDialect::Postgres && \is_string($value) && \str_contains($value, "\0")) {
                throw new QueryException(\sprintf(
                    'Parameter at index %d contains a NUL byte; Postgres carries text parameters as C '
                    . 'strings, so the value would reach the server truncated at that byte.',
                    $index,
                ), $sql);
            }

            $values[] = $value;
            $index++;
        }

        return $values;
    }
}
