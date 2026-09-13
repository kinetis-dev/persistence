<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Contract;

/**
 * Dialect marker: a link speaking MySQL. The query builder's dialect
 * detection keys off this — beyond that, the surface is SqlLink's.
 *
 * @extends SqlLink<MysqlTransaction>
 */
interface MysqlLink extends SqlLink
{
}
