<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Exception;

use Throwable;

/**
 * A query was rejected or failed — carries the SQL text for diagnostics,
 * and, where the driver reported one, the server's own error number as
 * the exception code. That number is what tells otherwise identical
 * failures apart: a MySQL deadlock (1213) from a lock-wait timeout
 * (1205), for one. It is zero when the failure never reached a server or
 * the driver reported no number.
 */
class QueryException extends SqlException
{
    public function __construct(
        string $message,
        private readonly string $query = '',
        ?Throwable $previous = null,
        int $vendorCode = 0,
    ) {
        parent::__construct($message, $vendorCode, $previous);
    }

    public function getQuery(): string
    {
        return $this->query;
    }
}
