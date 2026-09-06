<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

/**
 * Normalizes a raw MySQL-reported last-insert-id — mysqli's own
 * $insert_id (an int within PHP's integer range, a string past it) or
 * PDO::lastInsertId()'s string|false — into the int|string|null shape
 * {@see \Kinetis\Persistence\Contract\SqlResult::getLastInsertId()}
 * promises. Shared by MysqliAsyncClient and PdoMysqlClient so neither
 * can apply a different rule than the other.
 *
 * Every ordinary AUTO_INCREMENT id comes back as an int. An UNSIGNED
 * BIGINT id past PHP_INT_MAX stays the decimal string the driver
 * reported: an (int) cast would saturate at PHP_INT_MAX and name a
 * different row. Zero, false and null are MySQL's "this statement
 * generated no id".
 *
 * @internal
 */
final class MysqlInsertId
{
    public static function normalize(int|string|false|null $raw): int|string|null
    {
        if ($raw === false || $raw === null || $raw === '' || $raw === 0 || $raw === '0') {
            return null;
        }

        if (\is_int($raw)) {
            return $raw;
        }

        // Round-tripping through int is what decides whether the value
        // fits: it does not for anything past PHP_INT_MAX.
        return (string) (int) $raw === $raw ? (int) $raw : $raw;
    }
}
