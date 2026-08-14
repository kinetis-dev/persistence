<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Contract;

/**
 * Something SQL can be executed against — a driver client (pool) or an
 * in-flight transaction, exactly like the query builder and
 * TransactionGuard expect. Kinetis-owned: every driver in
 * Kinetis\Persistence\Driver implements this, and nothing in the
 * persistence stack references a client library's own types.
 */
interface SqlLink
{
    /**
     * Executes complete SQL text with no parameter binding.
     */
    public function query(string $sql): SqlResult;

    /**
     * Executes SQL with "?" positional placeholders bound from $params.
     * How binding is realized is the driver's business (server-side
     * parameters, or escaped client-side interpolation where the
     * backend's async mode has no bind step) — the safety contract is
     * identical either way: values never merge into SQL unescaped.
     *
     * @param array<int|string, mixed> $params
     */
    public function execute(string $sql, array $params = []): SqlResult;

    public function beginTransaction(): SqlTransaction;

    public function close(): void;

    public function isClosed(): bool;
}
