<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Kinetis\Persistence\Contract\SqlResult;
use PgSql\Connection;
use Revolt\EventLoop;

/**
 * Per-connection state for {@see PgsqlAsyncClient}: the libpq handle, its
 * exported socket, the Revolt onReadable watcher created (disabled) at
 * connect time, and the Suspension of whichever Fiber is currently
 * awaiting a result on it.
 *
 * @internal
 */
final class PgsqlAsyncConnection
{
    /** @var EventLoop\Suspension<SqlResult>|null */
    public ?EventLoop\Suspension $suspension = null;

    public bool $broken = false;

    /** Assigned immediately after construction, once the watcher closure can reference this instance. */
    public string $watcherId = '';

    /**
     * @param resource $socket
     */
    public function __construct(
        public readonly Connection $handle,
        public readonly mixed $socket,
    ) {}
}
