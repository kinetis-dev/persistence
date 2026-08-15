<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use PDO;
use PDOStatement;

/**
 * Type-aware parameter binding for the PDO drivers, replacing
 * PDOStatement::execute(array) — which binds every value as a string.
 * That string cast turns false into '' (rejected outright by Postgres
 * boolean columns) and loses the null/bool/int type information the
 * server-side prepare could otherwise use. Explicit PDO::PARAM_* types
 * keep bool/null/int parameters behaving identically across all four
 * drivers; floats and strings stay on the default string path, whose
 * float conversion is locale-independent and round-trip exact.
 *
 * @internal Shared by {@see PdoExecutionTrait} and {@see PdoTransaction}.
 */
final class PdoParamBinder
{
    /**
     * @param array<int|string, mixed> $params
     */
    public static function bind(PDOStatement $statement, array $params): void
    {
        $position = 1;

        foreach ($params as $value) {
            $statement->bindValue($position++, $value, match (true) {
                $value === null => PDO::PARAM_NULL,
                \is_bool($value) => PDO::PARAM_BOOL,
                \is_int($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            });
        }
    }
}
