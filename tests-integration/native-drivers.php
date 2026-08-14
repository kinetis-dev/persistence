<?php

declare(strict_types=1);

/**
 * Real-backend regression coverage for the native drivers — every driver
 * exercised against a live MySQL and Postgres: query/execute round trips
 * with native types, escaping edge cases, error recovery, concurrent
 * Fiber fan-out (wider than the pool, so the waiter queue is covered),
 * transactions, and the ConnectionOptions translation (charset actually
 * applied; unsupported options rejected loudly).
 *
 * Environment: MYSQL_HOST/MYSQL_USER/MYSQL_PASSWORD/MYSQL_DATABASE/
 * MYSQL_PORT and the POSTGRES_* equivalents, same as
 * transaction-guard.php.
 */

require __DIR__ . '/../vendor/autoload.php';

use Kinetis\Persistence\ConnectionOptions;
use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Driver\MysqliAsyncClient;
use Kinetis\Persistence\Driver\PdoMysqlClient;
use Kinetis\Persistence\Driver\PdoPgsqlClient;
use Kinetis\Persistence\Driver\PgsqlAsyncClient;
use Revolt\EventLoop;

function check(string $label, bool $condition): void
{
    echo ($condition ? "OK   " : "FAIL ") . $label . "\n";

    if (!$condition) {
        exit(1);
    }
}

/**
 * @param list<callable(): mixed> $tasks
 * @return list<mixed>
 */
function concurrently(array $tasks): array
{
    $fibers = [];

    foreach ($tasks as $index => $task) {
        $fiber = new Fiber(static function () use ($task): array {
            try {
                return ['ok', $task()];
            } catch (Throwable $e) {
                return ['error', $e];
            }
        });
        $fibers[$index] = $fiber;
        $fiber->start();
    }

    EventLoop::run();

    $results = [];

    foreach ($fibers as $index => $fiber) {
        [$status, $value] = $fiber->getReturn();

        if ($status === 'error') {
            throw $value;
        }

        $results[$index] = $value;
    }

    return $results;
}

function exerciseLink(string $name, SqlLink $db, string $autoIncrementColumn): void
{
    echo "=== {$name} ===\n";

    $db->query('DROP TABLE IF EXISTS drv_test');
    $db->query("CREATE TABLE drv_test (id {$autoIncrementColumn}, n INT NOT NULL, s VARCHAR(64) NOT NULL)");

    // execute() with params; the string includes quote/backslash/decoy-"?"
    // characters to exercise escaping and placeholder recognition.
    $tricky = "it's a \\ test ? not a placeholder";
    $result = $db->execute('INSERT INTO drv_test (n, s) VALUES (?, ?)', [42, $tricky]);
    check("{$name}: insert affected rows", $result->getRowCount() === 1);

    if (str_starts_with($name, 'mysql')) {
        check("{$name}: last insert id", $result->getLastInsertId() === 1);
    }

    $rows = [];

    foreach ($db->query('SELECT id, n, s FROM drv_test') as $row) {
        $rows[] = $row;
    }
    check("{$name}: select returns row", count($rows) === 1);
    check("{$name}: int is native", $rows[0]['n'] === 42);
    check("{$name}: string round-trips escaping", $rows[0]['s'] === $tricky);

    try {
        $db->execute('SELECT ?', [1, 2]);
        $mismatchThrew = false;
    } catch (Throwable) {
        $mismatchThrew = true;
    }
    check("{$name}: param count mismatch throws", $mismatchThrew);

    try {
        $db->query('SELECT broken syntax FROM');
        $errorThrew = false;
    } catch (Throwable) {
        $errorThrew = true;
    }
    check("{$name}: query error throws", $errorThrew);
    check("{$name}: usable after error", (int) ($db->query('SELECT 1 AS one')->fetchRow()['one'] ?? 0) === 1);

    // Concurrent fan-out through Fibers, wider than the async pools'
    // maxConnections so the waiter queue is exercised too.
    $fanOut = concurrently(array_fill(0, 30, fn (): mixed => $db->query('SELECT n FROM drv_test WHERE id = 1')->fetchRow()['n']));
    check("{$name}: 30-way concurrent fan-out", count($fanOut) === 30 && count(array_unique($fanOut)) === 1);

    // Transactions: commit persists, rollback discards.
    $tx = $db->beginTransaction();
    $tx->execute('INSERT INTO drv_test (n, s) VALUES (?, ?)', [1, 'committed']);
    check("{$name}: tx active", $tx->isActive());
    $tx->commit();
    check("{$name}: commit persisted", (int) $db->query('SELECT COUNT(*) AS c FROM drv_test')->fetchRow()['c'] === 2);

    $tx = $db->beginTransaction();
    $tx->execute('INSERT INTO drv_test (n, s) VALUES (?, ?)', [2, 'rolled back']);
    $tx->rollback();
    check("{$name}: rollback discarded", (int) $db->query('SELECT COUNT(*) AS c FROM drv_test')->fetchRow()['c'] === 2);

    $db->close();
    check("{$name}: closed", $db->isClosed());
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

exerciseLink(
    'mysqli-async',
    new MysqliAsyncClient(...[...$mysqlArgs, new ConnectionOptions(maxConnections: 4)]),
    'INT AUTO_INCREMENT PRIMARY KEY',
);
exerciseLink('pdo-mysql', new PdoMysqlClient(...$mysqlArgs), 'INT AUTO_INCREMENT PRIMARY KEY');
exerciseLink(
    'pgsql-async',
    new PgsqlAsyncClient(...[...$postgresArgs, new ConnectionOptions(maxConnections: 4)]),
    'SERIAL PRIMARY KEY',
);
exerciseLink('pdo-pgsql', new PdoPgsqlClient(...$postgresArgs), 'SERIAL PRIMARY KEY');

// ConnectionOptions translation, against real servers.
echo "=== connection options ===\n";

$mysqlLatin1 = new MysqliAsyncClient(...[...$mysqlArgs, new ConnectionOptions(charset: 'latin1')]);
$charset = $mysqlLatin1->query("SHOW VARIABLES LIKE 'character_set_client'")->fetchRow()['Value'] ?? null;
check('mysqli-async: configured charset is applied', $charset === 'latin1');
$mysqlLatin1->close();

$pgNamed = new PgsqlAsyncClient(...[...$postgresArgs, new ConnectionOptions(applicationName: 'kinetis-int-test')]);
$appName = $pgNamed->query('SHOW application_name')->fetchRow()['application_name'] ?? null;
check('pgsql-async: application_name is applied', $appName === 'kinetis-int-test');
$pgNamed->close();

try {
    new MysqliAsyncClient(...[...$mysqlArgs, new ConnectionOptions(applicationName: 'nope')]);
    $rejected = false;
} catch (InvalidArgumentException) {
    $rejected = true;
}
check('mysqli-async: unsupported option is rejected loudly', $rejected);

echo "\nALL CHECKS PASSED\n";
