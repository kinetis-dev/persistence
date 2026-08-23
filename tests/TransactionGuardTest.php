<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use Kinetis\Persistence\TransactionGuard;
use Kinetis\Persistence\Tests\Fixtures\InMemoryLogger;
use Kinetis\Persistence\Tests\Fixtures\FakeSqlLink;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class TransactionGuardTest extends TestCase
{
    public function test_transaction_commits_on_success(): void
    {
        $guard = new TransactionGuard(new NullLogger());
        $link = new FakeSqlLink();

        $result = $guard->transaction($link, static fn () => 'result');

        self::assertSame('result', $result);
        self::assertTrue($link->transactions[0]->committed);
        self::assertFalse($link->transactions[0]->rolledBack);
    }

    public function test_transaction_rolls_back_and_rethrows_on_failure(): void
    {
        $guard = new TransactionGuard(new NullLogger());
        $link = new FakeSqlLink();

        try {
            $guard->transaction($link, function (): never {
                throw new RuntimeException('boom');
            });
            self::fail('Expected a RuntimeException.');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertTrue($link->transactions[0]->rolledBack);
        self::assertFalse($link->transactions[0]->committed);
    }

    public function test_rollback_dangling_closes_a_transaction_left_open(): void
    {
        $guard = new TransactionGuard(new NullLogger());
        $link = new FakeSqlLink();

        $guard->beginTransaction($link);
        $guard->rollbackDangling();

        self::assertTrue($link->transactions[0]->rolledBack);
    }

    public function test_rollback_dangling_does_not_touch_an_already_closed_transaction(): void
    {
        $guard = new TransactionGuard(new NullLogger());
        $link = new FakeSqlLink();

        $guard->transaction($link, static fn () => null);
        $guard->rollbackDangling();

        self::assertFalse($link->transactions[0]->rolledBack);
        self::assertTrue($link->transactions[0]->committed);
    }

    public function test_rollback_dangling_is_safe_to_call_with_nothing_open(): void
    {
        $guard = new TransactionGuard(new NullLogger());

        $guard->rollbackDangling();

        self::assertTrue(true);
    }

    public function test_rollback_dangling_logs_a_warning_when_it_finds_one_to_close(): void
    {
        $logger = new InMemoryLogger();
        $guard = new TransactionGuard($logger);
        $link = new FakeSqlLink();

        $guard->beginTransaction($link);
        $guard->rollbackDangling();

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
    }

    public function test_rollback_dangling_does_not_log_when_nothing_was_left_open(): void
    {
        $logger = new InMemoryLogger();
        $guard = new TransactionGuard($logger);
        $link = new FakeSqlLink();

        $guard->transaction($link, static fn () => null);
        $guard->rollbackDangling();

        self::assertSame([], $logger->records);
    }
}
