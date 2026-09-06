<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Kinetis\Persistence\Exception\QueryException;

/**
 * The two MySQL server errors that end a transaction on its connection:
 * ER_LOCK_WAIT_TIMEOUT (1205) and ER_LOCK_DEADLOCK (1213).
 *
 * A deadlock is always resolved by rolling the losing transaction back
 * whole. A lock-wait timeout rolls back only the statement — unless
 * `innodb_rollback_on_timeout` is ON, where it rolls back the whole
 * transaction too, and nothing in the error says which setting is live.
 * So both are terminal here: the transaction ends, its connection is
 * discarded rather than handed to the next caller, and its outcome is
 * reported unknown, because it is.
 *
 * The server's own error number rides on the QueryException's code, so
 * a caller that wants to retry can still tell the two apart.
 *
 * @internal
 */
final class MysqlLockFailure
{
    private const int LOCK_WAIT_TIMEOUT = 1205;

    private const int DEADLOCK = 1213;

    public static function isTerminal(QueryException $failure): bool
    {
        return $failure->getCode() === self::LOCK_WAIT_TIMEOUT || $failure->getCode() === self::DEADLOCK;
    }
}
