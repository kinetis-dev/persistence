<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Kinetis\Persistence\Contract\PostgresTransaction;

/**
 * {@see PdoTransaction} carrying the Postgres dialect marker.
 */
final class PdoPgsqlTransaction extends PdoTransaction implements PostgresTransaction
{
}
