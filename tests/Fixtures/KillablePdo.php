<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Fixtures;

use PDO;
use PDOException;
use PDOStatement;

/**
 * A PDO connection the server can terminate: after kill(), every
 * prepare, query and beginTransaction on this handle fails the way
 * PostgreSQL reports a backend ended by `pg_terminate_backend()`. The
 * handle stays poisoned, so only a different handle can serve work
 * again.
 */
final class KillablePdo extends PDO
{
    private bool $killed = false;

    public function kill(): void
    {
        $this->killed = true;
    }

    #[\Override]
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->failIfKilled();

        return parent::prepare($query, $options);
    }

    #[\Override]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->failIfKilled();

        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    #[\Override]
    public function beginTransaction(): bool
    {
        $this->failIfKilled();

        return parent::beginTransaction();
    }

    private function failIfKilled(): void
    {
        if (!$this->killed) {
            return;
        }

        $e = new PDOException('SQLSTATE[57P01]: Admin shutdown: 7 FATAL: terminating connection due to administrator command');
        $e->errorInfo = ['57P01', 7, 'FATAL: terminating connection due to administrator command'];

        throw $e;
    }
}
