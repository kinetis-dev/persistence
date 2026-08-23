<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Exception;

/**
 * Transaction misuse: operating on a finished transaction, nesting where
 * the driver doesn't support it, or a failed COMMIT/ROLLBACK.
 */
class TransactionException extends SqlException
{
}
