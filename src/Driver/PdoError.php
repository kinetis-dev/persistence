<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use PDOException;

/**
 * What a PDOException carries beyond its message. SQLSTATE alone does
 * not tell a MySQL lock-wait timeout from a deadlock; the driver's own
 * error number does, and this is where it is read out.
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
}
