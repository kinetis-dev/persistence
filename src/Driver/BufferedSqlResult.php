<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use IteratorAggregate;
use Kinetis\Persistence\Contract\SqlResult;
use Traversable;

/**
 * The one buffered result implementation every driver shares — see
 * {@see SqlResult} for why buffering is part of the contract.
 *
 * @implements IteratorAggregate<int, array<string, mixed>>
 */
final class BufferedSqlResult implements SqlResult, IteratorAggregate
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
        private readonly ?int $lastInsertId = null,
    ) {}

    public function getIterator(): Traversable
    {
        yield from $this->rows;
    }

    public function fetchRow(): ?array
    {
        return $this->rows[$this->cursor++] ?? null;
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
}
