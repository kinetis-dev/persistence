<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Kinetis\Persistence\Contract\MysqlTransaction;

/**
 * {@see PdoTransaction} carrying the MySQL dialect marker.
 */
final class PdoMysqlTransaction extends PdoTransaction implements MysqlTransaction
{
}
