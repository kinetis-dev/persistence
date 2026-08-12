<?php

declare(strict_types=1);

/**
 * Real-backend regression coverage for Kinetis\Persistence\TransactionGuard
 * — commit-on-success, rollback-on-throw, and rollbackDangling() closing a
 * transaction that was opened directly and never explicitly closed. Works
 * identically for MySQL and Postgres, both exercised here.
 */

require __DIR__ . '/../vendor/autoload.php';

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use Kinetis\Persistence\TransactionGuard;
use Psr\Log\NullLogger;

function check(string $label, bool $condition): void
{
    echo ($condition ? "OK   " : "FAIL ") . $label . "\n";

    if (!$condition) {
        exit(1);
    }
}

function run(string $backend, $link): void
{
    echo "=== {$backend} ===\n";

    $link->execute('DROP TABLE IF EXISTS accounts');
    $link->execute('CREATE TABLE accounts (id INT PRIMARY KEY, balance INT NOT NULL)');
    $link->execute('INSERT INTO accounts (id, balance) VALUES (1, 100)');

    $guard = new TransactionGuard(new NullLogger());

    // transaction(): commits on success.
    $guard->transaction($link, function ($tx) {
        $tx->execute('UPDATE accounts SET balance = balance - 10 WHERE id = 1');
    });
    $row = $link->execute('SELECT balance FROM accounts WHERE id = 1')->fetchRow();
    check("{$backend}: transaction() commits on success", (int) $row['balance'] === 90);

    // transaction(): rolls back on throw.
    try {
        $guard->transaction($link, function ($tx) {
            $tx->execute('UPDATE accounts SET balance = balance - 1000 WHERE id = 1');
            throw new RuntimeException('deliberate failure');
        });
    } catch (RuntimeException) {
        // expected
    }
    $row = $link->execute('SELECT balance FROM accounts WHERE id = 1')->fetchRow();
    check("{$backend}: transaction() rolls back on throw", (int) $row['balance'] === 90);

    // rollbackDangling(): closes a transaction opened directly and never closed.
    $dangling = $guard->beginTransaction($link);
    $dangling->execute('UPDATE accounts SET balance = balance - 50 WHERE id = 1');
    check("{$backend}: the dangling transaction is still active before cleanup", $dangling->isActive());

    $guard->rollbackDangling();
    check("{$backend}: rollbackDangling() closed it", !$dangling->isActive());

    $row = $link->execute('SELECT balance FROM accounts WHERE id = 1')->fetchRow();
    check("{$backend}: the dangling change was rolled back, not applied", (int) $row['balance'] === 90);

    echo "\n";
}

$mysql = new MysqlConnectionPool(new MysqlConfig(
    host: getenv('MYSQL_HOST') ?: '127.0.0.1',
    user: getenv('MYSQL_USER') ?: 'testuser',
    password: getenv('MYSQL_PASSWORD') ?: 'testpass',
    database: getenv('MYSQL_DATABASE') ?: 'testdb',
));
$postgres = new PostgresConnectionPool(new PostgresConfig(
    host: getenv('POSTGRES_HOST') ?: '127.0.0.1',
    user: getenv('POSTGRES_USER') ?: 'testuser',
    password: getenv('POSTGRES_PASSWORD') ?: 'testpass',
    database: getenv('POSTGRES_DATABASE') ?: 'testdb',
));

run('MySQL', $mysql);
run('Postgres', $postgres);

echo "ALL CHECKS PASSED\n";
