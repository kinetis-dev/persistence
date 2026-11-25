<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Integration;

use Kinetis\Persistence\Contract\ReportsJsonDepth;
use Kinetis\Persistence\Driver\JsonDepth;
use Kinetis\Persistence\Exception\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The depth each client reports is what its server stores: a document
 * nested that deep is written to a JSON column and json_decode() reads it
 * back, and where the server, not the decoder, sets the limit, one level
 * deeper is refused. CI runs it against MySQL 9.7, MariaDB 11.4 and
 * PostgreSQL 18.
 */
final class JsonDepthTest extends DriverCase
{
    #[DataProvider('drivers')]
    public function test_the_reported_depth_is_what_the_server_stores(string $driver): void
    {
        $db = self::makeClient($driver);
        self::assertInstanceOf(ReportsJsonDepth::class, $db);
        $depth = $db->jsonDepth();

        if (self::isMysql($driver)) {
            $row = $db->query('SELECT VERSION() AS version')->fetchRow();
            self::assertIsArray($row);
            self::assertSame(JsonDepth::mysql((string) $row['version']), $depth);
        } else {
            self::assertSame(JsonDepth::DECODER, $depth);
        }

        $db->query('DROP TABLE IF EXISTS drv_json_depth');
        $db->query('CREATE TABLE drv_json_depth (id INT PRIMARY KEY, doc ' . (self::isMysql($driver) ? 'JSON' : 'JSONB') . ' NOT NULL)');
        $db->execute('INSERT INTO drv_json_depth (id, doc) VALUES (?, ?)', [1, self::nested($depth)]);
        $row = $db->query('SELECT doc FROM drv_json_depth WHERE id = 1')->fetchRow();
        self::assertIsArray($row);
        $stored = json_decode((string) $row['doc'], false, $depth + 1, JSON_THROW_ON_ERROR);
        self::assertSame(self::nested($depth), json_encode($stored, JSON_THROW_ON_ERROR, $depth));

        if ($depth !== JsonDepth::DECODER) {
            try {
                $db->execute('INSERT INTO drv_json_depth (id, doc) VALUES (?, ?)', [2, self::nested($depth + 1)]);
                self::fail("The server stored {$depth} + 1 levels.");
            } catch (QueryException) {
                self::assertNull($db->query('SELECT doc FROM drv_json_depth WHERE id = 2')->fetchRow());
            }
        }

        $db->query('DROP TABLE drv_json_depth');
        $db->close();
    }

    /** JSON text of $levels nested objects around a scalar. */
    private static function nested(int $levels): string
    {
        return str_repeat('{"a":', $levels) . '1' . str_repeat('}', $levels);
    }
}
