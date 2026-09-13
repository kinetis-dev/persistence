<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Contract;

/**
 * Dialect marker: a link speaking Postgres.
 *
 * @extends SqlLink<PostgresTransaction>
 */
interface PostgresLink extends SqlLink
{
}
