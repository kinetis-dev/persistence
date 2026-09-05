<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use ArrayObject;
use Kinetis\Instrumentation\NullTelemetry;
use Kinetis\Instrumentation\Telemetry;
use Kinetis\Instrumentation\TelemetryInterface;
use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Driver\BufferedSqlResult;
use Kinetis\Persistence\Driver\MysqliAsyncClient;
use Kinetis\Persistence\Driver\PdoMysqlClient;
use Kinetis\Persistence\Driver\PdoMysqlTransaction;
use Kinetis\Persistence\Driver\PdoPgsqlClient;
use Kinetis\Persistence\Driver\PdoPgsqlTransaction;
use Kinetis\Persistence\Driver\PgsqlAsyncClient;
use Kinetis\Persistence\Exception\QueryException;
use Kinetis\Persistence\Tests\Fixtures\DispatchCountingTransaction;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Where the parameter pre-flight runs, as distinct from what it decides.
 * {@see PdoStatementPreflightTest} pins the decisions; these cases pin
 * that every driver reaches them before it does anything else at all.
 *
 * The clients here are cold — constructed, never connected, pointed at
 * a port nothing answers on — which is the state that separates a
 * pre-dispatch check from one performed on the way through execution. A
 * driver validating any later would, for the same caller mistake, open
 * a telemetry span for a query it was never going to send, wait for a
 * pooled connection, connect, and set a charset and a collation on the
 * connection it opened; against an unreachable server it would surface
 * a ConnectionException rather than the QueryException the mistake
 * actually is, so the same argument list would even be reported
 * differently depending on whether the pool happened to be warm. The
 * spies are what prove none of that happens: a telemetry backend
 * recording every hook a driver can emit, a PDO handle that refuses
 * every call, and each client's own pool, read back empty.
 */
final class PreDispatchPreflightTest extends TestCase
{
    /** The exact diagnostic {@see \Kinetis\Persistence\Driver\SqlParamInterpolator::assertPositionalKeys()} throws. */
    private const string NON_LIST_PARAMS_MESSAGE = 'Query parameters must be a list keyed 0..n-1 in '
        . 'placeholder order; an associative or sparse array is rejected rather than reindexed, so a '
        . 'mis-keyed argument list surfaces at the call site instead of binding somewhere unintended.';

    /** The exact diagnostic {@see \Kinetis\Persistence\Driver\SqlParamInterpolator::rejectPlaceholderInsideExecutableComment()} throws. */
    private const string EXECUTABLE_COMMENT_PLACEHOLDER_MESSAGE = 'A "?" placeholder cannot appear inside a '
        . 'version-gated executable comment (/*!...*/ or /*M!...*/) — whether it is live depends on the '
        . 'connected server\'s own version, which the native and PDO drivers would resolve differently for '
        . 'the same query. Move the bound value outside the comment.';

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
     * Every shape of argument list the pre-flight refuses, on SQL both
     * dialects read identically — one per rule, since what these cases
     * pin is where the rules run rather than what each decides.
     *
     * @return iterable<string, array{string, array<array-key, mixed>, string}>
     */
    public static function invalidCalls(): iterable
    {
        yield 'associative keys' => ['SELECT ?, ?', ['a' => 1, 'b' => 2], self::NON_LIST_PARAMS_MESSAGE];
        yield 'sparse keys' => ['SELECT ?, ?', [0 => 1, 2 => 2], self::NON_LIST_PARAMS_MESSAGE];
        yield 'too few arguments' => ['SELECT ?, ?', [1], 'Query has 2 "?" placeholders but 1 parameter was given'];
        yield 'too many arguments' => ['SELECT ?', [1, 2], 'Query has 1 "?" placeholder but 2 parameters were given'];
        yield 'array value' => [
            'SELECT ?, ?',
            [1, [2]],
            'Parameter at index 1 is of type array; only null, bool, int, finite float and string can be bound.',
        ];
        yield 'non-finite float' => [
            'SELECT ?, ?',
            [1, \INF],
            'Parameter at index 1 is a non-finite float; only a finite float can be bound.',
        ];
    }

    /**
     * @return iterable<string, array{string, string, array<array-key, mixed>, string}>
     */
    public static function coldClientsAndInvalidCalls(): iterable
    {
        foreach (self::coldClients() as $driver => [$_]) {
            foreach (self::invalidCalls() as $label => [$sql, $params, $message]) {
                yield "{$driver}: {$label}" => [$driver, $sql, $params, $message];
            }
        }
    }

    /**
     * @param array<array-key, mixed> $params
     */
    #[DataProvider('coldClientsAndInvalidCalls')]
    public function test_a_cold_client_refuses_an_invalid_call_before_it_connects(
        string $driver,
        string $sql,
        array $params,
        string $message,
    ): void {
        $client = self::coldClient($driver);
        $hooks = $this->recordTelemetry();

        try {
            $client->execute($sql, $params);
            self::fail("Expected {$driver} to refuse the call.");
        } catch (QueryException $e) {
            self::assertSame($message, $e->getMessage());
        }

        self::assertSame([], $hooks->getArrayCopy(), "{$driver} reported a query it never sent.");
        self::assertNothingWasOpened($driver, $client);
    }

    /**
     * The client is still cold afterwards, so the pre-flight refused the
     * call rather than merely reporting it first — repeatedly, so a
     * refusal is not one connection's worth of luck.
     */
    #[DataProvider('coldClients')]
    public function test_a_refused_call_leaves_a_cold_client_cold_and_open(string $driver): void
    {
        $client = self::coldClient($driver);
        $hooks = $this->recordTelemetry();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $client->execute('SELECT ?', []);
                self::fail("Expected {$driver} to refuse the call.");
            } catch (QueryException $e) {
                self::assertSame('Query has 1 "?" placeholder but 0 parameters were given', $e->getMessage());
            }
        }

        self::assertSame([], $hooks->getArrayCopy(), "{$driver} reported a query it never sent.");
        self::assertNothingWasOpened($driver, $client);
        self::assertFalse($client->isClosed(), "{$driver} closed itself over a refused call.");
    }

    /**
     * The MySQL scan's own refusal — a "?" inside a version-gated
     * executable comment — is part of the same pre-flight, so it too
     * lands before there is a connection whose version the gate is even
     * about.
     */
    #[DataProvider('coldMysqlClients')]
    public function test_a_cold_mysql_client_refuses_an_executable_comment_placeholder_before_it_connects(string $driver): void
    {
        $client = self::coldClient($driver);
        $hooks = $this->recordTelemetry();

        try {
            $client->execute('SELECT /*!50000 ? */ 1', [1]);
            self::fail("Expected {$driver} to refuse the call.");
        } catch (QueryException $e) {
            self::assertSame(self::EXECUTABLE_COMMENT_PLACEHOLDER_MESSAGE, $e->getMessage());
        }

        self::assertSame([], $hooks->getArrayCopy(), "{$driver} reported a query it never sent.");
        self::assertNothingWasOpened($driver, $client);
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function coldMysqlClients(): iterable
    {
        yield 'pdo-mysql' => ['pdo-mysql'];
        yield 'mysqli-async' => ['mysqli-async'];
    }

    /**
     * A transaction already owns an open handle, so what it has to keep
     * off that handle is the statement: a refused call prepares nothing,
     * binds nothing, executes nothing, and opens no query span inside
     * the transaction's own.
     *
     * @param array<array-key, mixed> $params
     */
    #[DataProvider('pdoTransactionsAndInvalidCalls')]
    public function test_a_pdo_transaction_refuses_an_invalid_call_before_it_touches_its_handle(
        string $dialect,
        string $sql,
        array $params,
        string $message,
    ): void {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method(self::anything());

        $buildResult = static fn (PDOStatement $statement): BufferedSqlResult => new BufferedSqlResult([], 0, null);
        $transaction = $dialect === 'mysql'
            ? new PdoMysqlTransaction($pdo, $buildResult)
            : new PdoPgsqlTransaction($pdo, $buildResult);

        // Recording starts after construction: transactionStarted() is
        // the one hook that has legitimately fired already, and what
        // these cases forbid is a query hook for a query never sent.
        $hooks = $this->recordTelemetry();

        try {
            $transaction->execute($sql, $params);
            self::fail("Expected the {$dialect} transaction to refuse the call.");
        } catch (QueryException $e) {
            self::assertSame($message, $e->getMessage());
        }

        self::assertSame([], $hooks->getArrayCopy(), 'The transaction reported a query it never sent.');
        self::assertTrue($transaction->isActive(), 'A refused call closed the transaction.');
    }

    /**
     * @return iterable<string, array{string, string, array<array-key, mixed>, string}>
     */
    public static function pdoTransactionsAndInvalidCalls(): iterable
    {
        foreach (['mysql', 'postgres'] as $dialect) {
            foreach (self::invalidCalls() as $label => [$sql, $params, $message]) {
                yield "{$dialect}: {$label}" => [$dialect, $sql, $params, $message];
            }
        }
    }

    /**
     * Every transaction reaches its own dispatch through one gate on
     * AbstractTransaction, which is what puts the two native
     * transactions — whose pinned connections cannot exist without a
     * reachable server — under the same rule as the PDO pair. See
     * {@see DispatchCountingTransaction} for why the coverage is shaped
     * this way, and tests/Integration/BindableValueContractTest for the
     * real pair against real servers.
     *
     * @param array<array-key, mixed> $params
     */
    #[DataProvider('invalidCalls')]
    public function test_a_transaction_refuses_an_invalid_call_before_any_subclass_dispatch(
        string $sql,
        array $params,
        string $message,
    ): void {
        $transaction = new DispatchCountingTransaction();
        $hooks = $this->recordTelemetry();

        try {
            $transaction->execute($sql, $params);
            self::fail('Expected the transaction to refuse the call.');
        } catch (QueryException $e) {
            self::assertSame($message, $e->getMessage());
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
        $transaction = new DispatchCountingTransaction();

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
     * package emits — the five query and transaction hooks are the
     * whole set a driver can reach — and returns the record for the
     * case to assert on. Recording rather than refusing outright:
     * Telemetry contains a throwing backend by design, so an
     * expectation raised from inside a hook would be swallowed there
     * instead of failing the case that set it.
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
