<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Contract;

/**
 * A link that knows how deeply nested a JSON document its server stores
 * in a JSON column, counted in nested arrays and objects: the outermost
 * one is the first level.
 *
 * Every client in Kinetis\Persistence\Driver implements it:
 *
 * - MySQL stores 100 levels.
 * - MariaDB before 12.2 stores 31, the most its json_valid() check on a
 *   JSON column admits.
 * - MariaDB 12.2 and later, and PostgreSQL's json and jsonb, store more
 *   than PHP's json_decode() reads back in every shape, so the limit is
 *   that decoder's: 2499 levels, the most it reads when every level is
 *   an object.
 *
 * A MySQL-family client reads the server version from a connection's
 * handshake, so the first call opens a connection when none is open yet.
 */
interface ReportsJsonDepth
{
    /**
     * @return positive-int the deepest nesting, in levels, a JSON column of
     *         this link's server stores and json_decode() reads back
     */
    public function jsonDepth(): int;
}
