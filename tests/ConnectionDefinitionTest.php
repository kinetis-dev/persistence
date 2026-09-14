<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use InvalidArgumentException;
use Kinetis\Persistence\ConnectionDefinition;
use Kinetis\Persistence\ConnectionOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConnectionDefinitionTest extends TestCase
{
    public function test_defaults_select_auto_with_default_options_and_no_warming(): void
    {
        $definition = new ConnectionDefinition('mysql', 'db', 'app', 'app', 'secret');

        self::assertSame('auto', $definition->driver);
        self::assertEquals(new ConnectionOptions(), $definition->options);
        self::assertSame(0, $definition->warmConnections);
    }

    public function test_each_dialect_defaults_to_its_own_port_and_an_explicit_port_wins(): void
    {
        self::assertSame(3306, new ConnectionDefinition('mysql', 'db', 'app', 'app', 'secret')->port);
        self::assertSame(5432, new ConnectionDefinition('pgsql', 'db', 'app', 'app', 'secret')->port);
        self::assertSame(13306, new ConnectionDefinition('mysql', 'db', 'app', 'app', 'secret', port: 13306)->port);
    }

    public function test_an_unknown_dialect_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ConnectionDefinition $dialect must be "mysql" or "pgsql", got "sqlite".');

        new ConnectionDefinition('sqlite', 'db', 'app', 'app', 'secret');
    }

    public function test_an_unknown_driver_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ConnectionDefinition $driver must be "auto", "native", or "pdo", got "odbc".');

        new ConnectionDefinition('mysql', 'db', 'app', 'app', 'secret', driver: 'odbc');
    }

    /** @return iterable<string, array{int}> */
    public static function outOfRangePorts(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'beyond 65535' => [65536];
    }

    #[DataProvider('outOfRangePorts')]
    public function test_a_port_outside_the_tcp_range_is_rejected(int $port): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("ConnectionDefinition \$port must be a valid TCP port (1-65535), got {$port}.");

        new ConnectionDefinition('pgsql', 'db', 'app', 'app', 'secret', port: $port);
    }

    public function test_a_negative_warm_count_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ConnectionDefinition $warmConnections must not be negative, got -1.');

        new ConnectionDefinition('mysql', 'db', 'app', 'app', 'secret', warmConnections: -1);
    }
}
