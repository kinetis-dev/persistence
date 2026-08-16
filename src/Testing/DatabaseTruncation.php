<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Testing;

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\SqlLink;
use PHPUnit\Framework\Attributes\Before;

/**
 * Isolates each test by emptying the tables it names, before it runs.
 *
 * Slower than {@see DatabaseTransactions} — real DELETEs against real
 * tables — but it holds no transaction of its own, so it works for code
 * that opens its own transactions and for the pooled async drivers alike.
 * Reach for it when the faster trait's boundary applies.
 *
 * Truncating *before* rather than after is deliberate: a failed test
 * leaves its rows in place to inspect, and the next run still starts
 * clean either way.
 *
 *     use DatabaseTruncation;
 *
 *     protected function databaseLink(): SqlLink
 *     {
 *         return $this->app->get(MysqlLink::class);
 *     }
 *
 *     protected function tablesToTruncate(): array
 *     {
 *         return ['orders', 'order_items'];
 *     }
 *
 * Tables are named explicitly rather than discovered: a test suite that
 * empties every table it can find will eventually empty one holding
 * reference data the application needs, and the failure looks like a bug
 * in the code under test.
 */
trait DatabaseTruncation
{
    abstract protected function databaseLink(): SqlLink;

    /**
     * Emptied before each test, in the given order — list child tables
     * before their parents where foreign keys would otherwise block the
     * delete.
     *
     * @return list<string>
     */
    abstract protected function tablesToTruncate(): array;

    #[Before]
    protected function truncateTables(): void
    {
        $link = $this->databaseLink();

        foreach ($this->tablesToTruncate() as $table) {
            DatabaseIsolation::assertPlainIdentifier($table);

            // DELETE rather than TRUNCATE: TRUNCATE is DDL on MySQL and
            // commits implicitly, which would end any surrounding
            // transaction, and it cannot be used on a table another
            // table's foreign key points at without dropping the
            // constraint first.
            $link->execute("DELETE FROM {$this->quote($link, $table)}");
        }
    }

    private function quote(SqlLink $link, string $table): string
    {
        return $link instanceof MysqlLink ? "`{$table}`" : "\"{$table}\"";
    }
}
