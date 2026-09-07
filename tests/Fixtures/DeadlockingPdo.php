<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Fixtures;

use PDO;
use PDOException;
use PDOStatement;

/**
 * A PDO connection that answers one nominated statement with MySQL's
 * deadlock error — {@see \Kinetis\Persistence\Driver\MysqlLockFailure}'s
 * terminal case, which the SQLite connection these tests run on has no
 * way to produce. Everything else runs normally, so the transaction
 * reaches the failure with real work behind it.
 */
final class DeadlockingPdo extends PDO
{
    /** The one statement this connection refuses, and how. */
    public const string DEADLOCK_SQL = 'SELECT deadlock';

    public const int DEADLOCK_CODE = 1213;

    #[\Override]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($query !== self::DEADLOCK_SQL) {
            return parent::query($query, $fetchMode, ...$fetchModeArgs);
        }

        $message = 'Deadlock found when trying to get lock; try restarting transaction';
        $failure = new PDOException("SQLSTATE[40001]: Serialization failure: 1213 {$message}");
        // The driver's own error number, which is where PdoError reads
        // it from and the only thing that tells 1213 from 1205.
        $failure->errorInfo = ['40001', self::DEADLOCK_CODE, $message];

        throw $failure;
    }
}
