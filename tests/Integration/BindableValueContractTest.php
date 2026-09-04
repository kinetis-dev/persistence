<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Integration;

use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Contract\SqlTransaction;
use Kinetis\Persistence\Exception\QueryException;
use Kinetis\Persistence\Tests\Fixtures\StringableParameter;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

/**
 * The value half of the parameter contract, against real servers: an
 * argument is null, a bool, an int, a finite float or a string, and
 * anything else is refused with one diagnostic on all four drivers,
 * through a client and through a transaction alike.
 *
 * The four drivers reach a value by different routes — PDO binds it,
 * mysqli escapes it into the SQL text, ext-pgsql sends it alongside
 * the query — so parity across those routes is what these cases pin.
 * The other half is what a refusal costs the caller: the same client,
 * or the same transaction, still serves afterwards, and still binds
 * each position where it was written.
 *
 * Where a refused call stops — ahead of the telemetry span, the pool,
 * the connection and the prepare — is settled by
 * {@see \Kinetis\Persistence\Tests\PreDispatchPreflightTest} against
 * spies that can observe it; a SELECT against a real server cannot.
 */
final class BindableValueContractTest extends DriverCase
{
    #[DataProvider('driverPaths')]
    public function test_an_unsupported_value_is_refused_identically_and_leaves_the_link_usable(string $driver, string $path): void
    {
        $db = self::makeClient($driver);
        $link = $path === 'transaction' ? $db->beginTransaction() : $db;
        // Both placeholders are aliased, so the probe below reads each
        // position back by name rather than trusting which one an
        // unaliased column would carry.
        $sql = self::isMysql($driver)
            ? 'SELECT ? AS a, ? AS b'
            : 'SELECT CAST(? AS TEXT) AS a, CAST(? AS TEXT) AS b';
        $stream = \fopen('php://memory', 'r');
        self::assertIsResource($stream);

        foreach (self::unbindableValues($stream) as $label => [$value, $message]) {
            try {
                $link->execute($sql, ['fine', $value]);
                self::fail("Expected {$label} to be refused on {$driver} ({$path}).");
            } catch (QueryException $e) {
                self::assertSame($message, $e->getMessage(), "{$label} on {$driver} ({$path})");
            }
        }

        \fclose($stream);

        // Every refusal above cost the link nothing it needs to keep
        // serving: the same client — or the same transaction, still its
        // own — answers the next correct call, with both arguments
        // arriving at the positions they were written for.
        $row = $link->execute($sql, ['fine', 'also fine'])->fetchRow();

        self::assertSame('fine', $row['a'] ?? null, "{$driver} ({$path})");
        self::assertSame('also fine', $row['b'] ?? null, "{$driver} ({$path})");

        self::finish($db, $link);
    }

    #[DataProvider('driverPaths')]
    public function test_every_supported_value_kind_still_binds(string $driver, string $path): void
    {
        $db = self::makeClient($driver);
        $link = $path === 'transaction' ? $db->beginTransaction() : $db;
        $sql = self::isMysql($driver) ? 'SELECT ? AS v' : 'SELECT CAST(? AS TEXT) AS v';

        foreach ([null, 0, -7, \PHP_INT_MAX, 1.5, -0.5, '', 'x'] as $value) {
            $row = $link->execute($sql, [$value])->fetchRow();
            self::assertArrayHasKey('v', $row ?? [], "Binding {$value} on {$driver} ({$path}) returned no row.");
        }

        self::finish($db, $link);
    }

    /**
     * Every driver, through the client and through a transaction: two
     * separate statement caches on PDO, and the same interpolation
     * reached through two entry points on the native drivers.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function driverPaths(): iterable
    {
        foreach (self::drivers() as $name => [$driver]) {
            yield "{$name}: client" => [$driver, 'client'];
            yield "{$name}: transaction" => [$driver, 'transaction'];
        }
    }

    /**
     * Every value the shared contract refuses, with the exact
     * diagnostic it refuses it with — the same table
     * PdoStatementPreflightTest holds the doubles to, asserted here
     * against real servers.
     *
     * @param resource $stream
     * @return iterable<string, array{mixed, string}>
     */
    private static function unbindableValues(mixed $stream): iterable
    {
        $unsupported = static fn (string $type): string => "Parameter at index 1 is of type {$type}; "
            . 'only null, bool, int, finite float and string can be bound.';
        $nonFinite = 'Parameter at index 1 is a non-finite float; only a finite float can be bound.';

        yield 'array' => [[1, 2], $unsupported('array')];
        yield 'object' => [new stdClass(), $unsupported('stdClass')];
        yield 'stringable object' => [new StringableParameter(), $unsupported(StringableParameter::class)];
        yield 'closure' => [static fn (): int => 1, $unsupported('Closure')];
        yield 'resource' => [$stream, $unsupported('resource (stream)')];
        yield 'INF' => [\INF, $nonFinite];
        yield 'NAN' => [\NAN, $nonFinite];
    }

    /** Rolls back the transaction path, then hands the connection back. */
    private static function finish(SqlLink $db, SqlLink $link): void
    {
        if ($link instanceof SqlTransaction) {
            $link->rollback();
        }

        $db->close();
    }
}
