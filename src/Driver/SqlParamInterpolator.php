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
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];

            if ($quote !== null) {
                [$consumed, $quote] = self::consumeQuotedChar($sql, $i, $char, $quote, $length);
                $out .= $consumed;
                $i += \strlen($consumed);
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $out .= $char;
                $i++;
                continue;
            }

            if ($char === '?') {
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
     * The current char while inside a quoted region, honoring a backslash
     * escape (which consumes the following char too — except inside
     * backticks, where MySQL identifier quoting has no escape character
     * beyond doubling, handled implicitly since the closing quote just
     * flips $quote back to null on its own).
     *
     * @return array{0: string, 1: ?string} The literal text consumed (one
     *     char, or two for a backslash escape) and the resulting quote
     *     state — null once the closing quote itself was consumed.
     */
    private static function consumeQuotedChar(string $sql, int $i, string $char, string $quote, int $length): array
    {
        if ($char === '\\' && $quote !== '`' && $i + 1 < $length) {
            return [$char . $sql[$i + 1], $quote];
        }

        return [$char, $char === $quote ? null : $quote];
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
