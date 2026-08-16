<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Integration;

use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Testing\DatabaseTruncation;
use PHPUnit\Framework\Attributes\BeforeClass;

/**
 * The same order-independent proof {@see DatabaseTransactionsTest} uses,
 * for the strategy that empties tables instead of rolling back — and one
 * case the transaction trait cannot cover: code that opens its own
 * transaction while the test runs.
 */
final class DatabaseTruncationTest extends DriverCase
{
    use DatabaseTruncation;

    private ?SqlLink $link = null;

    protected function databaseLink(): SqlLink
    {
        return $this->link ??= self::makeClient('pdo-mysql');
    }

    #[BeforeClass]
    public static function createTable(): void
    {
        if (\getenv('MYSQL_HOST') === false) {
            return;
        }

        $link = self::makeClient('pdo-mysql');
        $link->query('CREATE TABLE IF NOT EXISTS trunc_test (id INT AUTO_INCREMENT PRIMARY KEY, note VARCHAR(32) NOT NULL)');
        $link->close();
    }

    /** @return list<string> */
    protected function tablesToTruncate(): array
    {
        return ['trunc_test'];
    }

    public function test_a_write_is_visible_inside_its_own_test(): void
    {
        $this->databaseLink()->execute('INSERT INTO trunc_test (note) VALUES (?)', ['first']);

        self::assertSame(1, $this->rowCount());
    }

    public function test_the_previous_tests_write_is_gone(): void
    {
        $this->databaseLink()->execute('INSERT INTO trunc_test (note) VALUES (?)', ['second']);

        self::assertSame(1, $this->rowCount());
    }

    /**
     * The case that separates the two strategies: committing a real
     * transaction from inside a test. Under DatabaseTransactions this
     * would throw on the nested begin; here it works, and the committed
     * row is still gone by the next test.
     */
    public function test_code_under_test_may_open_its_own_transaction(): void
    {
        $transaction = $this->databaseLink()->beginTransaction();
        $transaction->execute('INSERT INTO trunc_test (note) VALUES (?)', ['committed']);
        $transaction->commit();

        self::assertSame(1, $this->rowCount());
    }

    private function rowCount(): int
    {
        $row = $this->databaseLink()->query('SELECT COUNT(*) AS c FROM trunc_test')->fetchRow();

        return (int) ($row['c'] ?? 0);
    }
}
