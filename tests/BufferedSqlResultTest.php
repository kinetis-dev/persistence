<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use Kinetis\Persistence\Driver\BufferedSqlResult;
use PHPUnit\Framework\TestCase;

final class BufferedSqlResultTest extends TestCase
{
    public function test_foreach_yields_the_full_set_and_fetch_row_advances_independently(): void
    {
        $result = new BufferedSqlResult([['n' => 1], ['n' => 2]], 2, 1);

        self::assertSame([['n' => 1], ['n' => 2]], \iterator_to_array($result));
        self::assertSame(['n' => 1], $result->fetchRow());
        // foreach above did not consume the fetchRow() cursor.
        self::assertSame(['n' => 2], $result->fetchRow());
        self::assertNull($result->fetchRow());
    }

    public function test_metadata_accessors_return_what_was_captured(): void
    {
        $result = new BufferedSqlResult([], 3, null, 42);

        self::assertSame(3, $result->getRowCount());
        self::assertNull($result->getColumnCount());
        self::assertSame(42, $result->getLastInsertId());
    }

    public function test_last_insert_id_defaults_to_null(): void
    {
        self::assertNull(new BufferedSqlResult([], 0, 2)->getLastInsertId());
    }
}
