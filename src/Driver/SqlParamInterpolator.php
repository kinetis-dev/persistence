<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Kinetis\Persistence\Exception\QueryException;
use Closure;

/**
 * Rewrites the "?" positional placeholders Kinetis's query builder (and
 * amphp's own execute() contract) uses into whatever a native driver
 * needs: an escaped literal for mysqli (whose async mode has no
 * server-side bind step), or "$1".."$n" for pg_send_query_params.
 *
 * Placeholders are only recognized *outside* quoted regions — a "?"
 * inside a '...'/"..."/`...` literal is data, not a slot. Backslash
 * escapes inside single/double quotes are honored; backticks (MySQL
 * identifier quoting) have no escape character beyond doubling, which
 * this walk handles implicitly since the closing quote just flips state
 * back.
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
    public static function interpolate(string $sql, array $params, Closure $encode): string
    {
        $out = '';
        $paramIndex = 0;
        $length = \strlen($sql);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($quote !== null) {
                $out .= $char;

                if ($char === '\\' && $quote !== '`' && $i + 1 < $length) {
                    $out .= $sql[++$i];
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $out .= $char;
                continue;
            }

            if ($char === '?') {
                if (!\array_key_exists($paramIndex, $params)) {
                    throw new QueryException('Query has more "?" placeholders than parameters');
                }

                $out .= $encode($params[$paramIndex], $paramIndex);
                $paramIndex++;
                continue;
            }

            $out .= $char;
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
}
