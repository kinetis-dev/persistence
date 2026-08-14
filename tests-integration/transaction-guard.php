<?php

declare(strict_types=1);

/**
 * Real-backend regression coverage for Kinetis\Persistence\TransactionGuard
 * — commit-on-success, rollback-on-throw, and rollbackDangling() closing a
 * transaction that was opened directly and never explicitly closed. Works
 * identically for MySQL and Postgres, both exercised here.
 */

require __DIR__ . '/../vendor/autoload.php';

use Kinetis\Persistence\Driver\MysqliAsyncClient;
use Kinetis\Persistence\Driver\PdoMysqlClient;
use Kinetis\Persistence\Driver\PdoPgsqlClient;
use Kinetis\Persistence\Driver\PgsqlAsyncClient;
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

$mysqlArgs = [
    getenv('MYSQL_HOST') ?: '127.0.0.1',
    getenv('MYSQL_USER') ?: 'testuser',
    getenv('MYSQL_PASSWORD') ?: 'testpass',
    getenv('MYSQL_DATABASE') ?: 'testdb',
    (int) (getenv('MYSQL_PORT') ?: 3306),
];
$postgresArgs = [
    getenv('POSTGRES_HOST') ?: '127.0.0.1',
    getenv('POSTGRES_USER') ?: 'testuser',
    getenv('POSTGRES_PASSWORD') ?: 'testpass',
    getenv('POSTGRES_DATABASE') ?: 'testdb',
    (int) (getenv('POSTGRES_PORT') ?: 5432),
];

// Every driver must satisfy the guard identically.
run('MySQL/mysqli-async', new MysqliAsyncClient(...$mysqlArgs));
run('MySQL/pdo', new PdoMysqlClient(...$mysqlArgs));
run('Postgres/pgsql-async', new PgsqlAsyncClient(...$postgresArgs));
run('Postgres/pdo', new PdoPgsqlClient(...$postgresArgs));

echo "ALL CHECKS PASSED\n";
