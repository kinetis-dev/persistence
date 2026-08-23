<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Exception;

use RuntimeException;

/**
 * Base class for everything the persistence drivers throw.
 */
class SqlException extends RuntimeException
{
}
