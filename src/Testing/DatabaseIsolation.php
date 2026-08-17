<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Testing;

use InvalidArgumentException;
use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Driver\PdoMysqlClient;
use Kinetis\Persistence\Driver\PdoPgsqlClient;

/**
 * The checks {@see DatabaseTransactions} and {@see DatabaseTruncation}
 * share. Not part of either trait, so a test class using both doesn't
 * inherit the same method twice.
 *
 * @internal
 */
final class DatabaseIsolation
{
    /**
     * Whether every statement on this client necessarily lands on one
     * connection — the property transaction-based isolation depends on.
     * True for the PDO drivers, which hold exactly one; false for the
     * native async drivers, which pool.
     */
    public static function isSingleConnection(SqlLink $link): bool
    {
        return $link instanceof PdoMysqlClient || $link instanceof PdoPgsqlClient;
    }

    /**
     * Table names reach SQL as identifiers, not bound parameters, so they
     * are constrained to identifier characters — a test-only helper is
     * still a place a variable table name could smuggle SQL through.
     */
    public static function assertPlainIdentifier(string $table): void
    {
        if (preg_match('/^\w+$/', $table) !== 1) {
            throw new InvalidArgumentException(
                "Table name \"{$table}\" must contain only letters, digits, and underscores.",
            );
        }
    }
}
