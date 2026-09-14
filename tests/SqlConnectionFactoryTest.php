<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use InvalidArgumentException;
use Kinetis\Persistence\ConnectionDefinition;
use Kinetis\Persistence\ConnectionOptions;
use Kinetis\Persistence\Contract\SqlInstrumentation;
use Kinetis\Persistence\Driver\ContainedSqlInstrumentation;
use Kinetis\Persistence\Driver\MysqliAsyncClient;
use Kinetis\Persistence\Driver\PdoMysqlClient;
use Kinetis\Persistence\Driver\PdoPgsqlClient;
use Kinetis\Persistence\Driver\PgsqlAsyncClient;
use Kinetis\Persistence\Exception\ConnectionException;
use Kinetis\Persistence\SqlConnectionFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Construction only: no client here connects unless its definition asks
 * for warming, and every warming case points at port 1, which refuses.
 */
final class SqlConnectionFactoryTest extends TestCase
{
    /** @return iterable<string, array{'mysql'|'pgsql', 'native'|'pdo', class-string}> */
    public static function explicitDrivers(): iterable
    {
        yield 'mysql native' => ['mysql', 'native', MysqliAsyncClient::class];
        yield 'mysql pdo' => ['mysql', 'pdo', PdoMysqlClient::class];
        yield 'pgsql native' => ['pgsql', 'native', PgsqlAsyncClient::class];
        yield 'pgsql pdo' => ['pgsql', 'pdo', PdoPgsqlClient::class];
    }

    /**
     * @param 'mysql'|'pgsql' $dialect
     * @param 'native'|'pdo' $driver
     * @param class-string $expected
     */
    #[DataProvider('explicitDrivers')]
    public function test_an_explicit_driver_builds_its_client(string $dialect, string $driver, string $expected): void
    {
        self::assertInstanceOf($expected, SqlConnectionFactory::create(self::definition($dialect, driver: $driver)));
    }

    public function test_auto_selects_pdo_outside_a_persistent_runtime(): void
    {
        self::withEnvironment(['RR_MODE' => null], static function (): void {
            self::assertInstanceOf(PdoMysqlClient::class, SqlConnectionFactory::create(self::definition('mysql')));
            self::assertInstanceOf(PdoPgsqlClient::class, SqlConnectionFactory::create(self::definition('pgsql')));
        });
    }

    public function test_auto_selects_native_under_road_runner(): void
    {
        self::withEnvironment(['RR_MODE' => 'http'], static function (): void {
            self::assertInstanceOf(MysqliAsyncClient::class, SqlConnectionFactory::create(self::definition('mysql')));
            self::assertInstanceOf(PgsqlAsyncClient::class, SqlConnectionFactory::create(self::definition('pgsql')));
        });
    }

    /** AWS_LAMBDA_RUNTIME_API is not one of the two signals auto reads. */
    public function test_auto_selects_pdo_under_aws_lambda(): void
    {
        self::withEnvironment(['AWS_LAMBDA_RUNTIME_API' => '127.0.0.1:9001', 'RR_MODE' => null], static function (): void {
            self::assertInstanceOf(PdoMysqlClient::class, SqlConnectionFactory::create(self::definition('mysql')));
            self::assertInstanceOf(PdoPgsqlClient::class, SqlConnectionFactory::create(self::definition('pgsql')));
        });
    }

    public function test_create_builds_a_client_that_replaces_a_lost_session(): void
    {
        $client = SqlConnectionFactory::create(self::definition('mysql', driver: 'pdo'));

        self::assertFalse(self::property($client, 'singleSession'));
    }

    /** @return iterable<string, array{'mysql'|'pgsql', class-string}> */
    public static function dialects(): iterable
    {
        yield 'mysql' => ['mysql', PdoMysqlClient::class];
        yield 'pgsql' => ['pgsql', PdoPgsqlClient::class];
    }

    /**
     * @param 'mysql'|'pgsql' $dialect
     * @param class-string $expected
     */
    #[DataProvider('dialects')]
    public function test_single_session_builds_a_pinned_pdo_client_whatever_the_driver_says(string $dialect, string $expected): void
    {
        $client = SqlConnectionFactory::singleSession(self::definition($dialect, driver: 'native'));

        self::assertInstanceOf($expected, $client);
        self::assertTrue(self::property($client, 'singleSession'));
    }

    public function test_the_definition_reaches_the_client(): void
    {
        $options = new ConnectionOptions(sslMode: 'require', applicationName: 'app', maxConnections: 3);
        $client = SqlConnectionFactory::create(new ConnectionDefinition(
            dialect: 'pgsql',
            host: 'db.internal',
            database: 'orders',
            user: 'reporter',
            password: 'secret',
            port: 15432,
            driver: 'native',
            options: $options,
        ));

        self::assertSame('db.internal', self::property($client, 'host'));
        self::assertSame('orders', self::property($client, 'database'));
        self::assertSame('reporter', self::property($client, 'user'));
        self::assertSame('secret', self::property($client, 'password'));
        self::assertSame(15432, self::property($client, 'port'));
        self::assertSame($options, self::property($client, 'options'));
    }

    public function test_the_instrumentation_reaches_the_client_and_none_means_none(): void
    {
        $instrumentation = $this->createStub(SqlInstrumentation::class);

        foreach ([
            SqlConnectionFactory::create(self::definition('mysql', driver: 'native'), $instrumentation),
            SqlConnectionFactory::singleSession(self::definition('pgsql'), $instrumentation),
        ] as $client) {
            self::assertSame($instrumentation, self::wrappedInstrumentation($client));
        }

        self::assertNull(self::wrappedInstrumentation(SqlConnectionFactory::create(self::definition('mysql', driver: 'pdo'))));
    }

    public function test_an_option_the_selected_driver_cannot_honor_fails_loudly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('applicationName');

        SqlConnectionFactory::create(new ConnectionDefinition(
            dialect: 'mysql',
            host: '127.0.0.1',
            database: 'app',
            user: 'app',
            password: 'secret',
            driver: 'native',
            options: new ConnectionOptions(applicationName: 'app'),
        ));
    }

    #[RequiresPhpExtension('mysqli')]
    public function test_a_warm_count_connects_at_construction(): void
    {
        $this->expectException(ConnectionException::class);

        SqlConnectionFactory::create(self::definition('mysql', driver: 'native', port: 1, warmConnections: 1));
    }

    #[RequiresPhpExtension('pdo_mysql')]
    public function test_a_single_session_client_warms_too(): void
    {
        $this->expectException(ConnectionException::class);

        SqlConnectionFactory::singleSession(self::definition('mysql', port: 1, warmConnections: 1));
    }

    public function test_no_connection_is_opened_without_a_warm_count(): void
    {
        self::assertInstanceOf(
            MysqliAsyncClient::class,
            SqlConnectionFactory::create(self::definition('mysql', driver: 'native', port: 1)),
        );
    }

    public function test_warm_up_on_a_closed_client_throws(): void
    {
        $client = new MysqliAsyncClient('127.0.0.1', 'u', 'p', 'db', 1, new ConnectionOptions());
        $client->close();

        $this->expectException(ConnectionException::class);

        $client->warmUp(1);
    }

    /**
     * @param 'mysql'|'pgsql' $dialect
     * @param 'auto'|'native'|'pdo' $driver
     */
    private static function definition(
        string $dialect,
        string $driver = 'auto',
        ?int $port = null,
        int $warmConnections = 0,
    ): ConnectionDefinition {
        return new ConnectionDefinition(
            dialect: $dialect,
            host: '127.0.0.1',
            database: 'app',
            user: 'app',
            password: 'secret',
            port: $port,
            driver: $driver,
            warmConnections: $warmConnections,
        );
    }

    private static function property(object $object, string $name): mixed
    {
        return new ReflectionProperty($object, $name)->getValue($object);
    }

    private static function wrappedInstrumentation(object $client): ?SqlInstrumentation
    {
        $contained = self::property($client, 'instrumentation');
        self::assertInstanceOf(ContainedSqlInstrumentation::class, $contained);

        /** @var ?SqlInstrumentation */
        return self::property($contained, 'instrumentation');
    }

    /**
     * Runs $assertions with each named variable set (or unset, for null),
     * restoring every one afterwards.
     *
     * @param array<string, ?string> $variables
     * @param callable(): void $assertions
     */
    private static function withEnvironment(array $variables, callable $assertions): void
    {
        $original = [];

        foreach ($variables as $name => $value) {
            $original[$name] = \getenv($name);
            \putenv($value === null ? $name : "{$name}={$value}");
        }

        try {
            $assertions();
        } finally {
            foreach ($original as $name => $value) {
                \putenv($value === false ? $name : "{$name}={$value}");
            }
        }
    }
}
