<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Exception;

use Throwable;

/**
 * A query was rejected or failed — carries the SQL text for diagnostics,
 * and, where the driver reported them, the server's own error number as
 * the exception code and the SQLSTATE. The number is what tells otherwise
 * identical failures apart: a MySQL deadlock (1213) from a lock-wait
 * timeout (1205), for one. It is zero, and the SQLSTATE null, when the
 * failure never reached a server or the driver reported neither.
 */
class QueryException extends SqlException
{
    private const string POSTGRES_UNIQUE_VIOLATION = '23505';

    /** ER_DUP_ENTRY, on MySQL and MariaDB alike. */
    private const int MYSQL_DUPLICATE_ENTRY = 1062;

    public function __construct(
        string $message,
        private readonly string $query = '',
        ?Throwable $previous = null,
        int $vendorCode = 0,
        private readonly ?string $sqlState = null,
    ) {
        parent::__construct($message, $vendorCode, $previous);
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getSqlState(): ?string
    {
        return $this->sqlState;
    }

    /**
     * Whether the statement conflicted with a unique key or constraint:
     * SQLSTATE 23505 on PostgreSQL, error 1062 on MySQL and MariaDB. The
     * MySQL family reports SQLSTATE 23000 for every integrity violation,
     * a NOT NULL one included, so its error number decides.
     */
    public function isUniqueViolation(): bool
    {
        return $this->sqlState === self::POSTGRES_UNIQUE_VIOLATION || $this->getCode() === self::MYSQL_DUPLICATE_ENTRY;
    }
}
