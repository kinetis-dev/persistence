<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Fixtures;

use Kinetis\Persistence\Contract\PostgresTransaction;

/**
 * {@see FakeDriverTransaction} carrying the Postgres dialect marker,
 * which is what makes a failed statement abort the transaction.
 */
final class FakePostgresDriverTransaction extends FakeDriverTransaction implements PostgresTransaction
{
}
