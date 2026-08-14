<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Kinetis\Persistence\Exception\ConnectionException;

/**
 * Thrown only for a *dispatch-phase* failure that indicates the pooled
 * connection itself died (server closed an idle socket, network drop) —
 * i.e. the query never reached the server, so retrying it on a fresh
 * connection is safe even for non-idempotent statements. Reap-phase
 * failures (the server died *after* the query was sent) are never
 * represented by this class, precisely so nothing ever retries them:
 * the statement may have executed.
 *
 * Escapes to callers as its ConnectionException parent when the
 * one-retry budget is exhausted, or immediately when it happens on a
 * pinned transaction connection (where a retry would silently drop the
 * transaction state).
 *
 * @internal
 */
final class StaleConnectionException extends ConnectionException
{
}
