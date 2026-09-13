<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use PDOException;

/**
 * What a PDOException carries beyond its message: the SQLSTATE, and the
 * driver's own error number. SQLSTATE alone does not tell a MySQL
 * lock-wait timeout from a deadlock; the number does.
 *
 * @internal
 */
final class PdoError
{
    /** Zero where the driver reported no number of its own. */
    public static function vendorCode(PDOException $e): int
    {
        $code = $e->errorInfo[1] ?? null;

        return \is_int($code) ? $code : 0;
    }

    /** Null where the driver reported no SQLSTATE. */
    public static function sqlState(PDOException $e): ?string
    {
        $state = $e->errorInfo[0] ?? null;

        return \is_string($state) ? $state : null;
    }
}
