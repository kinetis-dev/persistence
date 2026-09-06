<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

/**
 * Quoting for one value in a libpq connection string, shared by the two
 * Postgres clients: {@see PgsqlAsyncClient} builds one directly, and
 * pdo_pgsql translates its DSN into one before libpq parses it. Without
 * the quotes a value carrying a space becomes two connection parameters
 * — `application_name=My App` sets `application_name=My` and then fails
 * on `App`.
 *
 * @internal
 */
final class LibpqValue
{
    public static function quote(string $value): string
    {
        return "'" . \addcslashes($value, "'\\") . "'";
    }
}
