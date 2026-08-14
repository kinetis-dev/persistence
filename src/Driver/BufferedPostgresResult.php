<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Amp\Postgres\PostgresResult;
use IteratorAggregate;
use Traversable;

/**
 * A fully-buffered result set for the native Postgres drivers
 * (ext-pgsql/PDO) — same buffering rationale as {@see BufferedMysqlResult}.
 *
 * @implements IteratorAggregate<int, array<string, mixed>>
 */
final class BufferedPostgresResult implements PostgresResult, IteratorAggregate
{
    private int $cursor = 0;

    /**
     * @param list<array<string, mixed>> $rows
     * @param int|null $rowCount Affected rows for DML, row count for SELECT.
     */
    public function __construct(
        private readonly array $rows,
        private readonly ?int $rowCount,
        private readonly ?int $columnCount,
    ) {}

    public function getIterator(): Traversable
    {
        yield from $this->rows;
    }

    public function fetchRow(): ?array
    {
        return $this->rows[$this->cursor++] ?? null;
    }

    public function getNextResult(): ?self
    {
        return null;
    }

    public function getRowCount(): ?int
    {
        return $this->rowCount;
    }

    public function getColumnCount(): ?int
    {
        return $this->columnCount;
    }
}
