<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Integration;

use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\BeforeClass;

/**
 * Proves the trait actually isolates, using the trait itself.
 *
 * Both tests insert exactly one row and assert they see exactly one. That
 * holds in any execution order if — and only if — each test's write is
 * rolled back before the next begins; without isolation whichever runs
 * second sees two. Asserting "the table is empty" instead would depend on
 * test order, which PHPUnit is free to randomize.
 */
final class DatabaseTransactionsTest extends DriverCase
{
    use DatabaseTransactions;

    private ?SqlLink $link = null;

    protected function databaseLink(): SqlLink
    {
        // makeClient() skips the test when MYSQL_HOST is unset, so this
        // stays environment-gated like every other real-backend test here.
        return $this->link ??= self::makeClient('pdo-mysql');
    }

    /**
     * DDL cannot live inside the trait's transaction: MySQL commits
     * implicitly around CREATE TABLE, which would end that transaction and
     * leave every later write in the test unisolated. Running it once per
     * class, before any #[Before], keeps the schema out of the way.
     */
    #[BeforeClass]
    public static function createTable(): void
    {
        if (\getenv('MYSQL_HOST') === false) {
            return;
        }

        $link = self::makeClient('pdo-mysql');
        $link->query('CREATE TABLE IF NOT EXISTS iso_test (id INT AUTO_INCREMENT PRIMARY KEY, note VARCHAR(32) NOT NULL)');
        $link->query('DELETE FROM iso_test');
        $link->close();
    }

    public function test_a_write_is_visible_inside_its_own_test(): void
    {
        $this->databaseLink()->execute('INSERT INTO iso_test (note) VALUES (?)', ['first']);

        self::assertSame(1, $this->rowCount());
    }

    public function test_the_previous_tests_write_is_gone(): void
    {
        $this->databaseLink()->execute('INSERT INTO iso_test (note) VALUES (?)', ['second']);

        self::assertSame(1, $this->rowCount());
    }

    private function rowCount(): int
    {
        $row = $this->databaseLink()->query('SELECT COUNT(*) AS c FROM iso_test')->fetchRow();

        return (int) ($row['c'] ?? 0);
    }
}
