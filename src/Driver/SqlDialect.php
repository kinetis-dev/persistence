<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

/**
 * The lexical differences {@see SqlParamInterpolator} needs to scan SQL
 * text correctly for each native driver — not the same thing as the
 * query-builder's own Mysql/Postgres dialect classes, which are about
 * identifier quoting and generated-key retrieval, a different concern.
 *
 * @internal
 */
enum SqlDialect
{
    case Mysql;
    case Postgres;
}
