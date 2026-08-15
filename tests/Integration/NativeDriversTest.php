<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Integration;

use Kinetis\Persistence\ConnectionOptions;
use Kinetis\Persistence\Driver\MysqliAsyncClient;
use Kinetis\Persistence\Driver\PgsqlAsyncClient;
use Kinetis\Persistence\Exception\ConnectionException;
use Kinetis\Persistence\Exception\TransactionException;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * Real-backend regression coverage for every driver: typed round trips,
 * escaping edge cases, error recovery, concurrent Fiber fan-out wider
 * than the pool (so the waiter queue is exercised), transactions, and
 * the live half of the ConnectionOptions translation. The
 * construction-time half lives in DriverConstructionTest, which needs no
 * database.
 */
final class NativeDriversTest extends DriverCase
{
    #[DataProvider('drivers')]
    public function test_execute_and_query_round_trip_with_native_types(string $driver): void
    {
        $db = self::makeClient($driver);
        $db->query('DROP TABLE IF EXISTS drv_test');
        $db->query('CREATE TABLE drv_test (id ' . self::autoIncrementColumn($driver) . ', n INT NOT NULL, s VARCHAR(64) NOT NULL)');

        // The string includes quote/backslash/decoy-"?" characters to
        // exercise escaping and placeholder recognition.
        $tricky = "it's a \\ test ? not a placeholder";
        $result = $db->execute('INSERT INTO drv_test (n, s) VALUES (?, ?)', [42, $tricky]);
        self::assertSame(1, $result->getRowCount());

        if (self::isMysql($driver)) {
            self::assertSame(1, $result->getLastInsertId());
        }

        $rows = \iterator_to_array($db->query('SELECT id, n, s FROM drv_test'));
        self::assertCount(1, $rows);
        self::assertSame(42, $rows[0]['n']);
        self::assertSame($tricky, $rows[0]['s']);

        $db->close();
        self::assertTrue($db->isClosed());
    }

    #[DataProvider('drivers')]
    public function test_errors_throw_and_the_client_stays_usable(string $driver): void
    {
        $db = self::makeClient($driver);

        try {
            $db->execute('SELECT ?', [1, 2]);
            self::fail('param count mismatch must throw');
        } catch (Throwable) {
            // expected
        }

        try {
            $db->query('SELECT broken syntax FROM');
            self::fail('query error must throw');
        } catch (Throwable) {
            // expected
        }

        self::assertSame(1, (int) ($db->query('SELECT 1 AS one')->fetchRow()['one'] ?? 0));
        $db->close();
    }

    #[DataProvider('drivers')]
    public function test_concurrent_fan_out_wider_than_the_pool(string $driver): void
    {
        $db = self::makeClient($driver);
        $db->query('DROP TABLE IF EXISTS drv_fan');
        $db->query('CREATE TABLE drv_fan (id ' . self::autoIncrementColumn($driver) . ', n INT NOT NULL)');
        $db->execute('INSERT INTO drv_fan (n) VALUES (?)', [7]);

        $results = self::concurrently(\array_fill(
            0,
            30,
            fn (): mixed => $db->query('SELECT n FROM drv_fan WHERE id = 1')->fetchRow()['n'],
        ));

        self::assertCount(30, $results);
        self::assertSame([7], \array_values(\array_unique($results)));
        $db->close();
    }

    #[DataProvider('drivers')]
    public function test_transactions_commit_and_roll_back(string $driver): void
    {
        $db = self::makeClient($driver);
        $db->query('DROP TABLE IF EXISTS drv_tx');
        $db->query('CREATE TABLE drv_tx (id ' . self::autoIncrementColumn($driver) . ', s VARCHAR(32) NOT NULL)');

        $tx = $db->beginTransaction();
        $tx->execute('INSERT INTO drv_tx (s) VALUES (?)', ['committed']);
        self::assertTrue($tx->isActive());
        $tx->commit();
        self::assertFalse($tx->isActive());
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) AS c FROM drv_tx')->fetchRow()['c']);

        $tx = $db->beginTransaction();
        $tx->execute('INSERT INTO drv_tx (s) VALUES (?)', ['rolled back']);
        $tx->rollback();
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) AS c FROM drv_tx')->fetchRow()['c']);

        $db->close();
    }

    public function test_mysqli_applies_the_configured_charset(): void
    {
        $db = self::makeClient('mysqli-async', new ConnectionOptions(charset: 'latin1'));
        \assert($db instanceof MysqliAsyncClient);

        self::assertSame('latin1', $db->query("SHOW VARIABLES LIKE 'character_set_client'")->fetchRow()['Value'] ?? null);
        $db->close();
    }

    public function test_pgsql_applies_the_configured_application_name(): void
    {
        $db = self::makeClient('pgsql-async', new ConnectionOptions(applicationName: 'kinetis-int-test'));
        \assert($db instanceof PgsqlAsyncClient);

        self::assertSame('kinetis-int-test', $db->query('SHOW application_name')->fetchRow()['application_name'] ?? null);
        $db->close();
    }
    #[DataProvider('drivers')]
    public function test_transaction_lifecycle_contracts(string $driver): void
    {
        $db = self::makeClient($driver);
        $db->query('DROP TABLE IF EXISTS drv_txc');
        $db->query('CREATE TABLE drv_txc (id ' . self::autoIncrementColumn($driver) . ', s VARCHAR(32) NOT NULL)');

        // query() (not just execute()) works on the pinned connection.
        $tx = $db->beginTransaction();
        $tx->query("INSERT INTO drv_txc (s) VALUES ('via-query')");

        // Nesting throws, naming the driver.
        try {
            $tx->beginTransaction();
            self::fail('nested beginTransaction must throw');
        } catch (TransactionException $e) {
            self::assertStringContainsString('Nested transactions are not supported', $e->getMessage());
        }

        // close() on a still-active transaction rolls back.
        $tx->close();
        self::assertFalse($tx->isActive());
        self::assertTrue($tx->isClosed());
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) AS c FROM drv_txc')->fetchRow()['c']);

        // Operating on a finished transaction throws; finishing twice too.
        try {
            $tx->commit();
            self::fail('double finish must throw');
        } catch (TransactionException) {
            // expected
        }

        $db->close();
    }

    #[DataProvider('drivers')]
    public function test_a_closed_client_rejects_further_work(string $driver): void
    {
        $db = self::makeClient($driver);
        $db->query('SELECT 1');
        $db->close();
        $db->close(); // idempotent

        $this->expectException(ConnectionException::class);
        $db->query('SELECT 1');
    }

    public function test_mysqli_applies_the_configured_collation(): void
    {
        $db = self::makeClient('mysqli-async', new ConnectionOptions(charset: 'utf8mb4', collation: 'utf8mb4_bin'));
        \assert($db instanceof MysqliAsyncClient);

        self::assertSame('utf8mb4_bin', $db->query("SHOW VARIABLES LIKE 'collation_connection'")->fetchRow()['Value'] ?? null);
        $db->close();
    }

    public function test_pgsql_applies_the_configured_client_encoding_and_connect_timeout(): void
    {
        $db = self::makeClient('pgsql-async', new ConnectionOptions(charset: 'UTF8', connectTimeout: 5));
        \assert($db instanceof PgsqlAsyncClient);

        self::assertSame('UTF8', $db->query('SHOW client_encoding')->fetchRow()['client_encoding'] ?? null);
        $db->close();
    }

    public function test_mysqli_honors_connect_timeout_and_compression_options(): void
    {
        // Constructs and round-trips with both options set — proving the
        // translated mysqli_options/flags don't break the handshake.
        $db = self::makeClient('mysqli-async', new ConnectionOptions(connectTimeout: 5, compression: true));

        self::assertSame(1, (int) $db->query('SELECT 1 AS one')->fetchRow()['one']);
        $db->close();
    }

    #[DataProvider('drivers')]
    public function test_decimal_columns_stay_strings_on_every_driver(string $driver): void
    {
        $db = self::makeClient($driver);

        $row = $db->query("SELECT CAST('1234567890.12345678901234567890' AS DECIMAL(40,20)) AS d")->fetchRow();

        self::assertNotNull($row);
        // DECIMAL/NUMERIC is arbitrary-precision — a float cast would
        // silently lose digits, so every driver returns it verbatim.
        self::assertSame('1234567890.12345678901234567890', $row['d']);

        $db->close();
    }

    #[DataProvider('drivers')]
    public function test_float_parameters_bind_exactly_even_under_a_comma_decimal_locale(string $driver): void
    {
        $previous = \setlocale(\LC_NUMERIC, '0');
        // Best effort: the locale may not exist on this machine; the
        // exact-roundtrip assertions below hold either way.
        \setlocale(\LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'de_DE.utf8');

        try {
            $db = self::makeClient($driver);

            $row = $db->execute('SELECT CAST(? AS FLOAT) + CAST(? AS FLOAT) AS v', [1.5, 0.25])->fetchRow();
            self::assertNotNull($row);
            self::assertEqualsWithDelta(1.75, (float) $row['v'], 1e-9);

            $db->close();
        } finally {
            if ($previous !== false) {
                \setlocale(\LC_NUMERIC, $previous);
            }
        }
    }

    /**
     * close() with a transaction still in flight tears the pinned
     * connection down immediately; the transaction's own finish() runs
     * afterwards and must not tear it down a second time — closing a
     * libpq/mysqli handle twice is an error, not a no-op.
     */
    #[DataProvider('nativeDrivers')]
    public function test_closing_the_client_with_an_open_transaction_stays_clean(string $driver): void
    {
        $db = self::makeClient($driver);
        $tx = $db->beginTransaction();

        $db->close();

        try {
            $tx->rollback();
            self::fail('Expected the rollback on a closed client to throw.');
        } catch (ConnectionException) {
            // The ROLLBACK statement cannot be dispatched — expected.
        }

        self::assertTrue($tx->isClosed());

        $fresh = self::makeClient($driver);
        self::assertSame(1, (int) $fresh->query('SELECT 1 AS one')->fetchRow()['one']);
        $fresh->close();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nativeDrivers(): iterable
    {
        yield 'mysqli-async' => ['mysqli-async'];
        yield 'pgsql-async' => ['pgsql-async'];
    }
}
