<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Integration;

use Kinetis\Persistence\TransactionGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Real-backend coverage for TransactionGuard on every driver —
 * commit-on-success, rollback-on-throw, and rollbackDangling() closing a
 * transaction that was opened directly and never explicitly finished.
 * The unit half (against fakes) lives in tests/TransactionGuardTest.php.
 */
final class TransactionGuardTest extends DriverCase
{
    #[DataProvider('drivers')]
    public function test_transaction_commits_on_success_and_rolls_back_on_throw(string $driver): void
    {
        $db = self::makeClient($driver);
        $db->query('DROP TABLE IF EXISTS guard_accounts');
        $db->query('CREATE TABLE guard_accounts (id INT PRIMARY KEY, balance INT NOT NULL)');
        $db->execute('INSERT INTO guard_accounts (id, balance) VALUES (?, ?)', [1, 100]);

        $guard = new TransactionGuard(new NullLogger());

        $guard->transaction($db, function ($tx): void {
            $tx->execute('UPDATE guard_accounts SET balance = balance - 10 WHERE id = 1');
        });
        self::assertSame(90, (int) $db->execute('SELECT balance FROM guard_accounts WHERE id = 1')->fetchRow()['balance']);

        try {
            $guard->transaction($db, function ($tx): void {
                $tx->execute('UPDATE guard_accounts SET balance = balance - 1000 WHERE id = 1');

                throw new RuntimeException('deliberate failure');
            });
            self::fail('the deliberate failure must propagate');
        } catch (RuntimeException) {
            // expected
        }
        self::assertSame(90, (int) $db->execute('SELECT balance FROM guard_accounts WHERE id = 1')->fetchRow()['balance']);

        $db->close();
    }

    #[DataProvider('drivers')]
    public function test_rollback_dangling_closes_an_abandoned_transaction(string $driver): void
    {
        $db = self::makeClient($driver);
        $db->query('DROP TABLE IF EXISTS guard_dangle');
        $db->query('CREATE TABLE guard_dangle (id INT PRIMARY KEY, balance INT NOT NULL)');
        $db->execute('INSERT INTO guard_dangle (id, balance) VALUES (?, ?)', [1, 100]);

        $guard = new TransactionGuard(new NullLogger());

        $dangling = $guard->beginTransaction($db);
        $dangling->execute('UPDATE guard_dangle SET balance = balance - 50 WHERE id = 1');
        self::assertTrue($dangling->isActive());

        $guard->rollbackDangling();
        self::assertFalse($dangling->isActive());
        self::assertSame(100, (int) $db->execute('SELECT balance FROM guard_dangle WHERE id = 1')->fetchRow()['balance']);

        $db->close();
    }
}
