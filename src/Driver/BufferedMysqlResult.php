<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Amp\Mysql\MysqlResult;
use IteratorAggregate;
use Traversable;

/**
 * A fully-buffered result set for the native MySQL drivers (mysqli/PDO).
 *
 * Buffering the whole set is a deliberate departure from amphp/mysql's
 * row-streaming: it means a consumer that stops iterating early leaves
 * nothing behind to dispose of, and the native drivers hand the rows
 * over in one already-complete block anyway. Result sets large enough
 * for streaming to matter should not go through a per-request web
 * driver in the first place.
 *
 * @implements IteratorAggregate<int, array<string, mixed>>
 */
final class BufferedMysqlResult implements MysqlResult, IteratorAggregate
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
        private readonly ?int $lastInsertId,
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

    public function getLastInsertId(): ?int
    {
        return $this->lastInsertId;
    }

    public function getColumnDefinitions(): ?array
    {
        return null;
    }
}
