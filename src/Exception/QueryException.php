<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Exception;

use Throwable;

/**
 * A query was rejected or failed — carries the SQL text for diagnostics.
 */
class QueryException extends SqlException
{
    public function __construct(
        string $message,
        private readonly string $query = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getQuery(): string
    {
        return $this->query;
    }
}
