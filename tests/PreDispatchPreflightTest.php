<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use ArrayObject;
use Kinetis\Instrumentation\NullTelemetry;
use Kinetis\Instrumentation\Telemetry;
use Kinetis\Instrumentation\TelemetryInterface;
use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Driver\MysqliAsyncClient;
use Kinetis\Persistence\Driver\PdoMysqlClient;
use Kinetis\Persistence\Driver\PdoPgsqlClient;
use Kinetis\Persistence\Driver\PgsqlAsyncClient;
use Kinetis\Persistence\Exception\QueryException;
use Kinetis\Persistence\Tests\Fixtures\FakeDriverTransaction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Where the parameter pre-flight runs, as distinct from what it decides.
 * {@see SqlParamInterpolatorTest} pins the decisions; these cases pin
 * that every driver reaches them before it does anything else at all.
 *
 * The clients here are cold — constructed, never connected, pointed at a
 * port nothing answers on — which is the state that separates a
 * pre-dispatch check from one performed on the way through execution. A
 * driver validating any later would open a telemetry span for a query it
 * was never going to send, wait for a pooled connection, and connect;
 * against an unreachable server it would surface a ConnectionException
 * rather than the QueryException the mistake actually is, so the same
 * argument list would be reported differently depending on whether the
 * pool happened to be warm.
 */
final class PreDispatchPreflightTest extends TestCase
{
    /**
     * The telemetry holder is worker-lifetime configuration, so a spy
     * installed for one case has to come back off for the next.
     */
    #[\Override]
    protected function tearDown(): void
    {
        Telemetry::global()->swap(new NullTelemetry());
    }

    /**
     * All four drivers, each named the way DriverCase names it.
     *
     * @return iterable<string, array{string}>
     */
    public static function coldClients(): iterable
    {
        yield 'pdo-mysql' => ['pdo-mysql'];
        yield 'pdo-pgsql' => ['pdo-pgsql'];
        yield 'mysqli-async' => ['mysqli-async'];
        yield 'pgsql-async' => ['pgsql-async'];
    }

    #[DataProvider('coldClients')]
    public function test_a_cold_client_refuses_an_invalid_call_before_it_connects(string $driver): void
    {
        // The native pgsql client refuses construction without
        // ext-sockets, so on a build lacking it there is no cold client
        // to hold to the rule; every other case still runs.
        if ($driver === 'pgsql-async' && !\function_exists('socket_import_stream')) {
            self::markTestSkipped('ext-sockets is not loaded — PgsqlAsyncClient cannot be constructed.');
        }

        $client = self::coldClient($driver);
        $hooks = $this->recordTelemetry();

        try {
            $client->execute('SELECT ?, ?', [1]);
            self::fail("Expected {$driver} to refuse the call.");
        } catch (QueryException $e) {
            self::assertSame('Query has 2 "?" placeholders but 1 parameter was given', $e->getMessage());
        }

        self::assertSame([], $hooks->getArrayCopy(), "{$driver} reported a query it never sent.");
        self::assertNothingWasOpened($driver, $client);
        self::assertFalse($client->isClosed(), "{$driver} closed itself over a refused call.");
    }

    /**
     * Every transaction reaches its own dispatch through one gate on
     * AbstractTransaction, which is what puts the two native
     * transactions — whose pinned connections cannot exist without a
     * reachable server — under the same rule as the PDO pair. See
     * {@see FakeDriverTransaction}, and
     * tests/Integration/BindableValueContractTest for the real four
     * against real servers.
     */
    public function test_a_transaction_refuses_an_invalid_call_before_any_subclass_dispatch(): void
    {
        $transaction = new FakeDriverTransaction();
        $hooks = $this->recordTelemetry();

        try {
            $transaction->execute('SELECT ?, ?', [1]);
            self::fail('Expected the transaction to refuse the call.');
        } catch (QueryException $e) {
            self::assertSame('Query has 2 "?" placeholders but 1 parameter was given', $e->getMessage());
        }

        self::assertSame(0, $transaction->dispatches, 'A refused call reached the pinned connection.');
        self::assertSame([], $hooks->getArrayCopy(), 'The transaction reported a query it never sent.');
        self::assertTrue($transaction->isActive(), 'A refused call closed the transaction.');
    }

    /**
     * The gate is a gate, not a wall: an accepted call still reaches the
     * subclass, carrying exactly the values the pre-flight admitted, in
     * placeholder order.
     */
    public function test_an_accepted_call_still_reaches_the_subclass_with_its_values(): void
    {
        $transaction = new FakeDriverTransaction();

        $transaction->execute('SELECT ?, ?', [1, 'x']);

        self::assertSame(1, $transaction->dispatches);
        self::assertSame([1, 'x'], $transaction->lastValues);
    }

    /** A client that has been constructed and has never connected. */
    private static function coldClient(string $driver): SqlLink
    {
        // Port 1 answers nothing, so a connection attempt would be a
        // visible failure rather than a silent success — though the
        // point of every case here is that none is ever made.
        return match ($driver) {
            'pdo-mysql' => new PdoMysqlClient('127.0.0.1', 'user', 'password', 'db', 1),
            'pdo-pgsql' => new PdoPgsqlClient('127.0.0.1', 'user', 'password', 'db', 1),
            'mysqli-async' => new MysqliAsyncClient('127.0.0.1', 'user', 'password', 'db', 1),
            'pgsql-async' => new PgsqlAsyncClient('127.0.0.1', 'user', 'password', 'db', 1),
        };
    }

    /**
     * Reads the driver's own record of what it holds open: the single
     * lazily-created handle on the PDO clients, the pool and its idle
     * list on the native ones.
     */
    private static function assertNothingWasOpened(string $driver, SqlLink $client): void
    {
        if (\str_starts_with($driver, 'pdo-')) {
            self::assertNull(
                new ReflectionProperty($client::class, 'pdo')->getValue($client),
                "{$driver} opened its connection for a refused call.",
            );

            return;
        }

        self::assertSame(
            [],
            new ReflectionProperty($client::class, 'connections')->getValue($client),
            "{$driver} opened a pooled connection for a refused call.",
        );
        self::assertSame(
            [],
            new ReflectionProperty($client::class, 'idle')->getValue($client),
            "{$driver} left a connection in its idle pool.",
        );
    }

    /**
     * Installs a telemetry backend recording, by name, every hook this
     * package emits, and returns the record for the case to assert on.
     * Recording rather than refusing outright: Telemetry contains a
     * throwing backend by design, so an expectation raised from inside a
     * hook would be swallowed there instead of failing the case.
     *
     * @return ArrayObject<int, string>
     */
    private function recordTelemetry(): ArrayObject
    {
        /** @var ArrayObject<int, string> $hooks */
        $hooks = new ArrayObject();
        $telemetry = $this->createStub(TelemetryInterface::class);

        foreach (['queryDispatched', 'queryServerStarted', 'queryReaped', 'transactionStarted', 'transactionEnded'] as $hook) {
            $telemetry->method($hook)->willReturnCallback(static function () use ($hooks, $hook): mixed {
                $hooks[] = $hook;

                return null;
            });
        }

        Telemetry::global()->swap($telemetry);

        return $hooks;
    }
}
