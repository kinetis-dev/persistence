<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Integration;

use Kinetis\Persistence\Exception\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A "?" inside a version-gated executable comment ("/*!...*\/" or
 * "/*M!...*\/") is rejected identically on both the native and PDO
 * drivers, against real MySQL and MariaDB — the parity the native
 * driver's own client-side scanner cannot otherwise guarantee, since
 * whether such a comment's content is even live SQL depends on the
 * connected server's actual version, which only PDO's native prepare
 * (deferring to the real server) would ever know and the native
 * driver's client-side interpolation never could.
 *
 * Every case below is exercised with the version gate both comfortably
 * below and comfortably above the real running server's own version —
 * Kinetis's own rejection is unconditional, so both must behave
 * identically; a driver that only rejected the "live" case (or only the
 * "dead" one) would be exactly the silent version-dependent divergence
 * this fix exists to close.
 */
final class ExecutableCommentTest extends DriverCase
{
    /**
     * The exact, stable diagnostic both drivers throw — asserted in full
     * below (mirroring SqlParamInterpolatorTest's own identical constant),
     * not just a middle substring that a regression in either closing
     * delimiter's own spelling could still slip through.
     */
    private const string EXECUTABLE_COMMENT_PLACEHOLDER_MESSAGE = 'A "?" placeholder cannot appear inside a '
        . 'version-gated executable comment (/*!...*/ or /*M!...*/) — whether it is live depends on the '
        . 'connected server\'s own version, which the native and PDO drivers would resolve differently for '
        . 'the same query. Move the bound value outside the comment.';

    private const string LOW_GATE = '00000';

    private const string HIGH_GATE = '99999999';

    #[DataProvider('mysqlDrivers')]
    public function test_a_placeholder_inside_a_mysql_executable_comment_is_rejected_regardless_of_gate(string $driver): void
    {
        $db = self::makeClient($driver);

        foreach ([self::LOW_GATE, self::HIGH_GATE] as $gate) {
            try {
                $db->execute("SELECT /*!{$gate} ? */ 1 AS n", [1]);
                self::fail("Expected a QueryException for gate {$gate} on {$driver}.");
            } catch (QueryException $e) {
                self::assertSame(
                    self::EXECUTABLE_COMMENT_PLACEHOLDER_MESSAGE,
                    $e->getMessage(),
                    "Gate {$gate} on {$driver} did not produce the expected rejection.",
                );
            }
        }

        // The rejection doesn't poison the connection -- an ordinary
        // query right after still works.
        self::assertSame(1, (int) ($db->query('SELECT 1 AS one')->fetchRow()['one'] ?? 0));

        $db->close();
    }

    #[DataProvider('mysqlDrivers')]
    public function test_a_placeholder_inside_a_mariadb_executable_comment_is_also_rejected(string $driver): void
    {
        $db = self::makeClient($driver);

        foreach ([self::LOW_GATE, self::HIGH_GATE] as $gate) {
            try {
                $db->execute("SELECT /*M!{$gate} ? */ 1 AS n", [1]);
                self::fail("Expected a QueryException for gate {$gate} on {$driver}.");
            } catch (QueryException $e) {
                self::assertSame(
                    self::EXECUTABLE_COMMENT_PLACEHOLDER_MESSAGE,
                    $e->getMessage(),
                    "Gate {$gate} on {$driver} did not produce the expected rejection.",
                );
            }
        }

        $db->close();
    }

    /**
     * The rejection is scoped precisely to a genuine placeholder — an
     * executable comment with none inside it (an optimizer hint, for
     * instance) still runs correctly on both drivers, exactly as it
     * always has, with the connected server free to decide on its own
     * whether the hint's own gate applies.
     */
    #[DataProvider('mysqlDrivers')]
    public function test_an_executable_comment_with_no_placeholder_still_executes_normally(string $driver): void
    {
        $db = self::makeClient($driver);

        $result = $db->query('SELECT /*!00000 STRAIGHT_JOIN */ 1 AS n');
        self::assertSame(1, (int) ($result->fetchRow()['n'] ?? 0));

        $db->close();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mysqlDrivers(): iterable
    {
        yield 'mysqli-async' => ['mysqli-async'];
        yield 'pdo-mysql' => ['pdo-mysql'];
    }
}
