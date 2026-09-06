<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Integration;

use Kinetis\Instrumentation\NullTelemetry;
use Kinetis\Instrumentation\Telemetry;
use Kinetis\Instrumentation\TelemetryInterface;
use Kinetis\Persistence\Exception\QueryException;

/**
 * One statement, one result — the SqlLink contract — against the real
 * servers, where the failure it prevents lives.
 *
 * A result set left unread poisons the connection it was left on: mysqli
 * answers every later borrower with "commands out of sync", PDO MySQL
 * with "cannot execute queries while other unbuffered queries are
 * active", and neither error clears on its own. So each case here
 * asserts twice: the call is refused, and the client still works
 * afterwards.
 */
final class OneResultPerCallTest extends DriverCase
{
    /** The telemetry holder is a per-process singleton. */
    protected function tearDown(): void
    {
        Telemetry::global()->swap(new NullTelemetry());
    }

    /**
     * A stored procedure returning a result set leaves the final OK
     * packet unread. next_result() would drain it, but it blocks the
     * event loop, so the connection is discarded instead and the pool
     * replaces it.
     */
    public function test_mysqli_refuses_extra_result_sets_and_does_not_poison_its_pool(): void
    {
        $db = self::makeClient('mysqli-async');
        $db->query('DROP PROCEDURE IF EXISTS one_result_probe');
        $db->query('CREATE PROCEDURE one_result_probe() BEGIN SELECT 1 AS n; END');

        try {
            $db->query('CALL one_result_probe()');
            self::fail('Expected the extra result set to be refused.');
        } catch (QueryException $e) {
            self::assertStringContainsString('One statement per call', $e->getMessage());
        }

        // The poisoned connection was discarded rather than pooled: a
        // fresh one answers normally.
        self::assertSame(1, (int) $db->query('SELECT 1 AS n')->fetchRow()['n']);

        $db->query('DROP PROCEDURE IF EXISTS one_result_probe');
        $db->close();
    }

    /** PDO is blocking already, so the extra rowsets are drained rather than the one connection discarded. */
    public function test_pdo_mysql_refuses_extra_result_sets_and_stays_usable(): void
    {
        $db = self::makeClient('pdo-mysql');
        $db->query('DROP PROCEDURE IF EXISTS one_result_probe');
        $db->query('CREATE PROCEDURE one_result_probe() BEGIN SELECT 1 AS n; END');

        try {
            $db->query('CALL one_result_probe()');
            self::fail('Expected the extra result set to be refused.');
        } catch (QueryException $e) {
            self::assertStringContainsString('One statement per call', $e->getMessage());
        }

        self::assertSame(1, (int) $db->query('SELECT 1 AS n')->fetchRow()['n']);

        $db->query('DROP PROCEDURE IF EXISTS one_result_probe');
        $db->close();
    }

    /**
     * A later result set can carry the server's own error rather than
     * rows — what a procedure raising SIGNAL after a SELECT produces.
     * That reaches the caller as this package's own exception, and the
     * span records the failure it is: the result is not built, and not
     * reported, until every result set has been read.
     */
    public function test_pdo_mysql_reports_an_error_in_a_later_result_set(): void
    {
        $db = self::makeClient('pdo-mysql');
        $db->query('DROP PROCEDURE IF EXISTS later_error_probe');
        $db->query(
            'CREATE PROCEDURE later_error_probe() BEGIN SELECT 1 AS n; '
            . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the second result set failed'; END",
        );

        $telemetry = $this->createMock(TelemetryInterface::class);
        $telemetry->method('queryDispatched')->willReturn('token');
        $telemetry->expects(self::once())
            ->method('queryReaped')
            ->with('token', self::isInstanceOf(QueryException::class));
        Telemetry::global()->swap($telemetry);

        try {
            $db->query('CALL later_error_probe()');
            self::fail('Expected the failing result set to be reported.');
        } catch (QueryException $e) {
            self::assertStringContainsString('the second result set failed', $e->getMessage());
        } finally {
            Telemetry::global()->swap(new NullTelemetry());
        }

        // The cursor was closed on the way out, so the connection is
        // clean for whatever runs next.
        self::assertSame(1, (int) $db->query('SELECT 1 AS n')->fetchRow()['n']);

        $db->query('DROP PROCEDURE IF EXISTS later_error_probe');
        $db->close();
    }

    /**
     * ext-pgsql's simple protocol runs a semicolon-separated string as
     * several statements and reports one result each. The drain reads
     * every one of them — never blocking on a result libpq has not
     * parsed yet — so the connection comes back healthy and the call is
     * still refused.
     */
    public function test_pgsql_refuses_a_multi_statement_string_and_keeps_the_connection_healthy(): void
    {
        $db = self::makeClient('pgsql-async');

        try {
            $db->query('SELECT 1 AS n; SELECT 2 AS n');
            self::fail('Expected the second result set to be refused.');
        } catch (QueryException $e) {
            self::assertStringContainsString('One statement per call', $e->getMessage());
            self::assertStringContainsString('2 result sets', $e->getMessage());
        }

        self::assertSame(1, (int) $db->query('SELECT 1 AS n')->fetchRow()['n']);
        $db->close();
    }

    /**
     * The event loop keeps running while the drain waits: a second Fiber
     * on its own pooled connection finishes its own query before a
     * deliberately slow multi-statement call ahead of it settles.
     */
    public function test_the_pgsql_drain_never_blocks_the_event_loop(): void
    {
        $db = self::makeClient('pgsql-async');
        $order = [];

        self::concurrently([
            static function () use ($db, &$order): void {
                try {
                    $db->query('SELECT pg_sleep(0.4); SELECT 1');
                } catch (QueryException) {
                    // The one-result rule, which is not what this case
                    // is about.
                }

                $order[] = 'slow';
            },
            static function () use ($db, &$order): void {
                $db->query('SELECT 1');
                $order[] = 'fast';
            },
        ]);

        self::assertSame(['fast', 'slow'], $order);
        $db->close();
    }
}
