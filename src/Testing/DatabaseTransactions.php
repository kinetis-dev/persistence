<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Testing;

use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Contract\SqlTransaction;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * Isolates each test by opening a transaction before it and rolling back
 * after, so nothing a test writes survives into the next one and no
 * cleanup code is needed.
 *
 * The rollback covers writes the application makes through the container's
 * own client, not just ones the test issues directly: the PDO drivers hold
 * a single connection, and a transaction opened on it encloses every
 * later statement on that same connection.
 *
 * That single-connection property is what makes this work, and it is also
 * its boundary. Two cases need {@see DatabaseTruncation} instead:
 *
 * - **Code under test that opens its own transaction.** The drivers reject
 *   nested transactions deliberately, so `TransactionGuard::transaction()`
 *   or any explicit `beginTransaction()` inside the code being tested
 *   throws while this trait holds one open. It fails loudly rather than
 *   silently losing isolation.
 * - **The native async drivers** (`DB_DRIVER=native`), which pool several
 *   connections — a transaction on one of them encloses nothing the others
 *   do. Tests run under the CLI, where `auto` already selects PDO, so this
 *   only applies to a suite that forces `native` explicitly; the trait
 *   rejects that combination at setup rather than reporting false
 *   isolation.
 *
 * A test supplies the connection to isolate:
 *
 *     use DatabaseTransactions;
 *
 *     protected function databaseLink(): SqlLink
 *     {
 *         return $this->app->get(MysqlLink::class);
 *     }
 *
 * Uses #[Before]/#[After] rather than setUp()/tearDown() so a test class
 * keeps its own lifecycle hooks without having to remember a parent call.
 */
trait DatabaseTransactions
{
    private ?SqlTransaction $kinetisTestTransaction = null;

    /**
     * The connection to isolate — normally the one the application itself
     * resolves, so the test and the code under test share it.
     */
    abstract protected function databaseLink(): SqlLink;

    #[Before]
    protected function beginDatabaseTransaction(): void
    {
        $link = $this->databaseLink();

        if (!DatabaseIsolation::isSingleConnection($link)) {
            self::markTestSkipped(
                'DatabaseTransactions needs a single-connection driver: the pooled async drivers '
                . 'spread statements across connections, so a transaction on one of them would not '
                . 'isolate the others. Set DB_DRIVER=pdo for the test suite, or use DatabaseTruncation.',
            );
        }

        $this->kinetisTestTransaction = $link->beginTransaction();
    }

    #[After]
    protected function rollBackDatabaseTransaction(): void
    {
        if ($this->kinetisTestTransaction?->isActive() === true) {
            $this->kinetisTestTransaction->rollback();
        }

        $this->kinetisTestTransaction = null;
    }
}
