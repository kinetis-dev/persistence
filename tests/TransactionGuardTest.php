<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests;

use Kinetis\Container\AppScope;
use Kinetis\Container\TransactionGuardHook;
use Kinetis\Persistence\Exception\TransactionException;
use Kinetis\Persistence\TransactionGuard;
use Kinetis\Persistence\Tests\Fixtures\InMemoryLogger;
use Kinetis\Persistence\Tests\Fixtures\FakeSqlLink;
use Kinetis\Persistence\Tests\Fixtures\FakeSqlTransaction;
use Kinetis\Persistence\Tests\Fixtures\ThrowingLogger;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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

    /**
     * A cleanup fault on one connection must never leak transactions/locks
     * on every later tracked one — rollbackDangling() attempts every
     * tracked transaction independently.
     */
    public function test_a_first_dangling_transactions_isActive_failure_does_not_prevent_a_later_one_from_being_rolled_back(): void
    {
        $guard = new TransactionGuard(new InMemoryLogger());
        $link = new FakeSqlLink();

        $guard->beginTransaction($link);
        $guard->beginTransaction($link);
        $link->transactions[0]->failOnIsActive = true;

        try {
            $guard->rollbackDangling();
            self::fail('Expected a TransactionException.');
        } catch (TransactionException) {
        }

        self::assertFalse($link->transactions[0]->rolledBack, 'inspection itself failed, so rollback() was never even reached for this one');
        self::assertTrue($link->transactions[1]->rolledBack, 'the second transaction was still attempted despite the first one failing');
    }

    public function test_a_first_dangling_transactions_rollback_failure_does_not_prevent_a_later_one_from_being_rolled_back(): void
    {
        $guard = new TransactionGuard(new InMemoryLogger());
        $link = new FakeSqlLink();

        $guard->beginTransaction($link);
        $guard->beginTransaction($link);
        $link->transactions[0]->failOnRollback = true;

        try {
            $guard->rollbackDangling();
            self::fail('Expected a TransactionException.');
        } catch (TransactionException) {
        }

        self::assertFalse($link->transactions[0]->rolledBack, 'rollback() itself threw, so this one never actually closed');
        self::assertTrue($link->transactions[1]->rolledBack, 'the second transaction was still attempted despite the first one failing');
    }

    /**
     * A broken logger must never be able to affect the actual safety-net
     * behavior: a warning()-log failure while reporting an already-
     * successful rollback must not misclassify that rollback as a
     * failure, must not prevent a later tracked transaction from being
     * attempted, and must not itself surface as a TransactionException —
     * the rollback genuinely succeeded, the logger just couldn't say so.
     */
    public function test_a_log_failure_reporting_a_successful_rollback_does_not_misclassify_it_or_block_a_later_one(): void
    {
        $logger = new ThrowingLogger();
        $logger->throwOnWarning = true;
        $guard = new TransactionGuard($logger);
        $link = new FakeSqlLink();

        $guard->beginTransaction($link);
        $guard->beginTransaction($link);

        // Both rollbacks are expected to succeed; only the success log
        // call is broken — so this must not throw at all.
        $guard->rollbackDangling();

        self::assertTrue($link->transactions[0]->rolledBack, 'the first transaction was still genuinely rolled back despite the logger failing to report it');
        self::assertTrue($link->transactions[1]->rolledBack, 'the second transaction was still attempted and rolled back');

        $errors = array_values(array_filter($logger->records, static fn (array $r): bool => $r['level'] === 'error'));
        self::assertSame([], $errors, 'a broken success-log call is not a rollback failure');
    }

    /**
     * The same proof for the failure-reporting path: an error()-log
     * failure while reporting a genuine isActive()/rollback failure must
     * not prevent a later tracked transaction from being attempted.
     */
    public function test_a_log_failure_reporting_a_genuine_rollback_failure_does_not_block_a_later_one(): void
    {
        $logger = new ThrowingLogger();
        $logger->throwOnError = true;
        $guard = new TransactionGuard($logger);
        $link = new FakeSqlLink();

        $guard->beginTransaction($link);
        $guard->beginTransaction($link);
        $link->transactions[0]->failOnRollback = true;

        try {
            $guard->rollbackDangling();
            self::fail('Expected a TransactionException — the rollback failure itself is still real.');
        } catch (TransactionException) {
        }

        self::assertFalse($link->transactions[0]->rolledBack, 'rollback() itself threw, so this one never actually closed');
        self::assertTrue($link->transactions[1]->rolledBack, 'the second transaction was still attempted despite the first one failing to both roll back and log');
    }

    /**
     * Tracking is settled before any transaction is touched, not after —
     * so a transaction a call already attempted, successfully or not, is
     * never retried by a later call.
     */
    public function test_a_failed_rollback_is_not_retried_on_a_second_call(): void
    {
        $guard = new TransactionGuard(new InMemoryLogger());
        $link = new FakeSqlLink();

        $guard->beginTransaction($link);
        $link->transactions[0]->failOnRollback = true;

        try {
            $guard->rollbackDangling();
            self::fail('Expected a TransactionException.');
        } catch (TransactionException) {
        }

        // If the failed transaction were still tracked, this call would
        // attempt rollback() again — still toggled to fail — and throw a
        // second time. It doesn't, because the first call already cleared
        // tracking regardless of the failure.
        $guard->rollbackDangling();

        self::assertTrue(true);
    }

    public function test_rollback_dangling_does_not_log_success_for_a_failed_rollback(): void
    {
        $logger = new InMemoryLogger();
        $guard = new TransactionGuard($logger);
        $link = new FakeSqlLink();

        $guard->beginTransaction($link);
        $link->transactions[0]->failOnRollback = true;

        try {
            $guard->rollbackDangling();
            self::fail('Expected a TransactionException.');
        } catch (TransactionException $e) {
            self::assertStringContainsString('Failed to roll back', $e->getMessage());
            self::assertInstanceOf(LogicException::class, $e->getPrevious());
        }

        $warnings = array_values(array_filter($logger->records, static fn (array $r): bool => $r['level'] === 'warning'));
        $errors = array_values(array_filter($logger->records, static fn (array $r): bool => $r['level'] === 'error'));

        self::assertSame([], $warnings, 'no success warning for a rollback that actually failed');
        self::assertCount(1, $errors, 'the failure is still observable through the log, not only the thrown exception');
        self::assertInstanceOf(LogicException::class, $errors[0]['context']['exception']);
    }

    /**
     * A rollback failure while handling an existing callback/commit
     * failure must never silently replace it — $e, the reason cleanup
     * started in the first place, is always what's thrown.
     */
    public function test_transaction_preserves_the_original_failure_when_rollback_also_fails(): void
    {
        $logger = new InMemoryLogger();
        $guard = new TransactionGuard($logger);
        $link = new FakeSqlLink();

        try {
            $guard->transaction($link, static function (FakeSqlTransaction $transaction): never {
                $transaction->failOnRollback = true;

                throw new RuntimeException('original failure');
            });
            self::fail('Expected a RuntimeException.');
        } catch (RuntimeException $e) {
            self::assertSame('original failure', $e->getMessage());
        }

        $errors = array_values(array_filter($logger->records, static fn (array $r): bool => $r['level'] === 'error'));
        self::assertCount(1, $errors, 'the rollback failure is still observable through the log, just not thrown in place of the original');
        self::assertInstanceOf(LogicException::class, $errors[0]['context']['exception']);
    }

    /**
     * The commit-fails-plus-rollback-fails variant of the test above —
     * both a callback failure and a commit failure enter the same catch
     * block in transaction(), and both were called out as needing
     * coverage, not only the callback one.
     */
    public function test_transaction_preserves_the_commit_failure_when_rollback_also_fails(): void
    {
        $logger = new InMemoryLogger();
        $guard = new TransactionGuard($logger);
        $link = new FakeSqlLink();

        try {
            $guard->transaction($link, static function (FakeSqlTransaction $transaction): string {
                $transaction->failOnCommit = true;
                $transaction->failOnRollback = true;

                return 'unused';
            });
            self::fail('Expected a LogicException.');
        } catch (LogicException $e) {
            self::assertSame('Commit failed.', $e->getMessage());
        }

        $errors = array_values(array_filter($logger->records, static fn (array $r): bool => $r['level'] === 'error'));
        self::assertCount(1, $errors, 'the rollback failure is still observable through the log, just not thrown in place of the commit failure');
        self::assertInstanceOf(LogicException::class, $errors[0]['context']['exception']);
        self::assertSame('Rollback failed.', $errors[0]['context']['exception']->getMessage());
    }

    /**
     * The real bug a prior fix still had: leaving a helper-managed
     * transaction tracked after its own rollback failed meant
     * rollbackDangling() — wired as a RequestScope dispose hook, run from
     * a `finally` — would retry it at scope disposal. If that retry also
     * failed, rollbackDangling()'s own throw would silently replace
     * whatever exception was already propagating from transaction() the
     * moment dispose() ran, the exact failure-erasing bug transaction()
     * itself already guards against one level down. Proven here with the
     * real RequestScope/dispose() machinery, not TransactionGuard in
     * isolation — the unit-level test above alone kept passing even with
     * that bug present, since it never exercised scope disposal at all.
     */
    public function test_the_original_callback_failure_survives_scope_disposal_even_when_rollback_fails_twice(): void
    {
        $app = new AppScope();
        $app->boot();
        $scope = $app->createRequestScope();
        TransactionGuardHook::registerIfAvailable($scope);

        /** @var TransactionGuard $guard */
        $guard = $scope->get(TransactionGuard::class);
        $link = new FakeSqlLink();

        try {
            try {
                $guard->transaction($link, static function (FakeSqlTransaction $transaction): never {
                    $transaction->failOnRollback = true;

                    throw new RuntimeException('original callback failure');
                });
                self::fail('Expected a RuntimeException.');
            } finally {
                // The real dispose hook: if the transaction were still
                // tracked here, rollbackDangling() would retry it — still
                // toggled to fail — and its own throw would replace the
                // RuntimeException currently propagating out of the try
                // block above.
                $scope->dispose();
            }
        } catch (RuntimeException $e) {
            self::assertSame('original callback failure', $e->getMessage());
        }
    }

    /** The same scope-disposal proof, for the commit-fails variant. */
    public function test_the_original_commit_failure_survives_scope_disposal_even_when_rollback_fails_twice(): void
    {
        $app = new AppScope();
        $app->boot();
        $scope = $app->createRequestScope();
        TransactionGuardHook::registerIfAvailable($scope);

        /** @var TransactionGuard $guard */
        $guard = $scope->get(TransactionGuard::class);
        $link = new FakeSqlLink();

        try {
            try {
                $guard->transaction($link, static function (FakeSqlTransaction $transaction): string {
                    $transaction->failOnCommit = true;
                    $transaction->failOnRollback = true;

                    return 'unused';
                });
                self::fail('Expected a LogicException.');
            } finally {
                $scope->dispose();
            }
        } catch (LogicException $e) {
            self::assertSame('Commit failed.', $e->getMessage());
        }
    }

    /**
     * The same scope-disposal proof, but with the configured logger
     * itself also broken (throwing from error()) on top of the callback
     * and rollback both failing — a logging failure while handling an
     * existing primary failure must never replace it either, the same
     * way a rollback failure alone must not.
     */
    public function test_the_original_callback_failure_survives_scope_disposal_even_when_error_logging_also_fails(): void
    {
        $logger = new ThrowingLogger();
        $logger->throwOnError = true;

        $app = new AppScope();
        $app->instance(LoggerInterface::class, $logger);
        $app->boot();
        $scope = $app->createRequestScope();
        TransactionGuardHook::registerIfAvailable($scope);

        /** @var TransactionGuard $guard */
        $guard = $scope->get(TransactionGuard::class);
        $link = new FakeSqlLink();

        try {
            try {
                $guard->transaction($link, static function (FakeSqlTransaction $transaction): never {
                    $transaction->failOnRollback = true;

                    throw new RuntimeException('original callback failure');
                });
                self::fail('Expected a RuntimeException.');
            } finally {
                $scope->dispose();
            }
        } catch (RuntimeException $e) {
            self::assertSame('original callback failure', $e->getMessage());
        }
    }

    /**
     * A transaction the helper closes itself — commit or rollback — must
     * not remain tracked until request end; tracking should only ever
     * represent genuinely outstanding work.
     */
    public function test_a_transaction_closed_via_the_helper_is_not_visited_again_by_rollback_dangling(): void
    {
        $logger = new InMemoryLogger();
        $guard = new TransactionGuard($logger);
        $link = new FakeSqlLink();

        $guard->transaction($link, static fn () => 'result');

        // Toggled only now, after transaction() has already returned:
        // if this transaction were still tracked, rollbackDangling()
        // would call isActive() on it and that call would throw. Toggling
        // it only after the fact — rather than relying on isActive()
        // already correctly answering false — is what actually proves
        // this transaction was untracked, not merely inert.
        $link->transactions[0]->failOnIsActive = true;

        $guard->rollbackDangling();

        self::assertSame([], $logger->records, 'the transaction was never visited at all, so nothing was logged');
    }
}
