<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Kinetis\Persistence\Contract\MysqlTransaction;
use Kinetis\Persistence\Contract\PrefersPreparedStatements;

/**
 * {@see PdoTransaction} carrying the MySQL dialect marker.
 */
final class PdoMysqlTransaction extends PdoTransaction implements MysqlTransaction, PrefersPreparedStatements
{
}
