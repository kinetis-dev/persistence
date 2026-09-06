<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Kinetis\Persistence\Contract\MysqlTransaction;
use Kinetis\Persistence\Contract\PrefersPreparedStatements;
use Kinetis\Persistence\Exception\QueryException;

/**
 * {@see PdoTransaction} carrying the MySQL dialect marker, and the one
 * rule PDO's own transaction status is not allowed to decide.
 */
final class PdoMysqlTransaction extends PdoTransaction implements MysqlTransaction, PrefersPreparedStatements
{
    /**
     * PDO::inTransaction() reports the status flag the server sent with
     * the last packet, and after a lock-wait timeout or a deadlock that
     * flag saying "still in a transaction" is not proof the server kept
     * this transaction's work — see {@see MysqlLockFailure}. The
     * conservative answer is the same one the native driver gives.
     */
    #[\Override]
    protected function isTerminalFailure(QueryException $failure): bool
    {
        return MysqlLockFailure::isTerminal($failure);
    }
}
