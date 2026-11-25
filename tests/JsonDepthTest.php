<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use Kinetis\Persistence\Driver\JsonDepth;
use Kinetis\Persistence\Driver\PdoPgsqlClient;
use Kinetis\Persistence\Driver\PgsqlAsyncClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JsonDepthTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function serverVersions(): iterable
    {
        yield 'MySQL 9.7' => ['9.7.4', 100];
        yield 'MySQL 8.0' => ['8.0.40', 100];
        yield 'MariaDB 11.4 behind the replication prefix' => ['5.5.5-11.4.13-MariaDB-ubu2404', 31];
        yield 'MariaDB 11.4' => ['11.4.13-MariaDB-ubu2404', 31];
        yield 'MariaDB 12.1' => ['12.1.2-MariaDB', 31];
        yield 'MariaDB 12.2' => ['12.2.0-MariaDB', 2499];
        yield 'MariaDB 12.2 behind the replication prefix' => ['5.5.5-12.2.2-MariaDB', 2499];
        yield 'MariaDB 13.0' => ['13.0.2-MariaDB-ubu2604', 2499];
        yield 'MariaDB 10.11' => ['10.11.9-MariaDB', 31];
    }

    #[DataProvider('serverVersions')]
    public function test_a_mysql_family_server_version_maps_to_the_depth_its_json_columns_store(string $version, int $depth): void
    {
        self::assertSame($depth, JsonDepth::mysql($version));
    }

    public function test_a_postgres_client_reports_the_decoder_limit_without_connecting(): void
    {
        $unreachable = ['unreachable.invalid', 'user', 'secret', 'db', 1];

        self::assertSame(2499, new PgsqlAsyncClient(...$unreachable)->jsonDepth());
        self::assertSame(2499, new PdoPgsqlClient(...$unreachable)->jsonDepth());
    }
}
