<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use Kinetis\Persistence\Driver\PdoError;
use PDOException;
use PHPUnit\Framework\TestCase;

final class PdoErrorTest extends TestCase
{
    public function test_the_sqlstate_and_the_driver_code_are_read_from_error_info(): void
    {
        $e = new PDOException('SQLSTATE[23505]: Unique violation');
        $e->errorInfo = ['23505', 7, 'ERROR: duplicate key value violates unique constraint'];

        self::assertSame('23505', PdoError::sqlState($e));
        self::assertSame(7, PdoError::vendorCode($e));
    }

    public function test_an_exception_without_error_info_reports_neither(): void
    {
        $e = new PDOException('could not connect');

        self::assertNull(PdoError::sqlState($e));
        self::assertSame(0, PdoError::vendorCode($e));
    }
}
