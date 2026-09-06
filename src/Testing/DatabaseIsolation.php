<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Testing;

use InvalidArgumentException;

/**
 * The checks {@see DatabaseTruncation} needs. A class rather than a
 * private method on the trait, so a test class using the trait does not
 * inherit it as its own method.
 *
 * @internal
 */
final class DatabaseIsolation
{
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
