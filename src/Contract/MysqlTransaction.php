<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Contract;

/**
 * A MySQL transaction — carries the dialect marker so `new Query($tx)`
 * detects the dialect the same way it does for a plain link.
 */
interface MysqlTransaction extends SqlTransaction, MysqlLink
{
}
