<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Fixtures;

use PDO;
use PDOException;

/**
 * A PDO connection whose ROLLBACK the server refuses — what a session
 * that died between the last statement and the rollback looks like from
 * here. It is the one way to reach the terminal transition's failure
 * path without a server to break on purpose.
 */
final class RollbackRefusingPdo extends PDO
{
    #[\Override]
    public function rollBack(): bool
    {
        throw new PDOException('SQLSTATE[HY000]: General error: the server refused the rollback');
    }
}
