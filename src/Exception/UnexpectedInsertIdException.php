<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Exception;

/**
 * A MySQL driver reported a last-insert-id value that cannot possibly
 * be a real generated id: negative, non-numeric, or past MySQL's own
 * UNSIGNED BIGINT ceiling — something
 * {@see \Kinetis\Persistence\Contract\SqlResult::getLastInsertId()}'s
 * own contract (int|string|null, where a string is always a canonical
 * decimal representation within that range) has no honest way to
 * carry. Never observed from a real mysqli/PDO MySQL backend; thrown
 * rather than silently passed through, since a caller receiving such a
 * value from getLastInsertId() would have no way to tell it apart from
 * a genuine, if oddly large, generated id.
 *
 * The message is a fixed, non-reflective diagnostic — the malformed
 * value itself is never interpolated into it, deliberately: it is
 * driver-reported data of otherwise-unknown shape, and there is
 * nothing an application needs to act on this invariant failure that
 * requires seeing the raw value, so it is never carried into a log
 * line or an exception trace.
 */
final class UnexpectedInsertIdException extends SqlException
{
    public static function forMalformedValue(): self
    {
        return new self(
            'The database driver reported a last-insert-id value that is not a valid, non-negative '
            . "MySQL AUTO_INCREMENT id — it is neither a recognized 'no id was generated' signal nor a "
            . 'decimal value within UNSIGNED BIGINT\'s range.',
        );
    }
}
