<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Contract;

/**
 * A Postgres transaction — carries the dialect marker so `new Query($tx)`
 * detects the dialect the same way it does for a plain link.
 */
interface PostgresTransaction extends SqlTransaction, PostgresLink
{
}
