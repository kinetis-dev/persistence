<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

/**
 * @internal The JSON nesting each server stores, for the clients'
 *           {@see \Kinetis\Persistence\Contract\ReportsJsonDepth}.
 */
final class JsonDepth
{
    /**
     * The most levels json_decode() reads back in every shape, whatever
     * depth it is given: nested objects stop at 2499, nested arrays at
     * 4998.
     */
    public const int DECODER = 2499;

    private const int MYSQL = 100;

    private const int MARIADB_BEFORE_12_2 = 31;

    /**
     * The limit for a MySQL-family server version string as the handshake
     * reports it, such as "9.7.4" or "11.4.13-MariaDB-ubu2404". A MariaDB
     * server may prefix its version with "5.5.5-"; the version after it
     * decides.
     *
     * @return positive-int
     */
    public static function mysql(string $serverVersion): int
    {
        if (!str_contains($serverVersion, 'MariaDB')) {
            return self::MYSQL;
        }

        preg_match('/^(?:5\.5\.5-)?(\d+)\.(\d+)/', $serverVersion, $version);

        return [(int) ($version[1] ?? 0), (int) ($version[2] ?? 0)] >= [12, 2] ? self::DECODER : self::MARIADB_BEFORE_12_2;
    }
}
