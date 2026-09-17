<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use PDO;
use PDOStatement;

/**
 * Type-aware parameter binding for the PDO drivers, replacing
 * PDOStatement::execute(array) — which binds every value as a string.
 * That string cast turns false into '' (rejected outright by Postgres
 * boolean columns) and loses the null/bool/int type information the
 * server-side prepare could otherwise use. Explicit PDO::PARAM_* types
 * keep bool/null/int parameters behaving identically across all four
 * drivers.
 *
 * A float is bound as PDO::PARAM_STR like a string, but the text is
 * this package's rather than PDO's own cast: that cast is the
 * "precision" INI setting's, which keeps 14 significant digits by
 * default and would store a different number than the caller bound
 * ({@see SqlParamInterpolator::encodeFloat()}). Strings are bound
 * unchanged.
 *
 * Walks $params in iteration order, binding positions 1..n for the n
 * values it is given and nothing else. Both halves of that are only
 * safe because {@see PdoStatementCache} has already held the call to a
 * list of exactly one argument per placeholder: iteration order is the
 * position, and no position is left carrying what an earlier execution
 * of a reused statement bound to it. The same pre-flight is why
 * every value arriving here is one
 * {@see SqlParamInterpolator::assertBindableValues()} admits, so this
 * loop has no arm for anything else.
 *
 * @internal Called by {@see PdoStatementCache}.
 */
final class PdoParamBinder
{
    /**
     * @param list<null|bool|int|float|string> $params
     */
    public static function bind(PDOStatement $statement, array $params): void
    {
        $position = 1;

        foreach ($params as $value) {
            $statement->bindValue(
                $position++,
                \is_float($value) ? SqlParamInterpolator::encodeFloat($value) : $value,
                match (true) {
                    $value === null => PDO::PARAM_NULL,
                    \is_bool($value) => PDO::PARAM_BOOL,
                    \is_int($value) => PDO::PARAM_INT,
                    // An encoded float or a string.
                    default => PDO::PARAM_STR,
                },
            );
        }
    }
}
