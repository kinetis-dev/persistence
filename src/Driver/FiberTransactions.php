<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Fiber;
use Kinetis\Persistence\Exception\TransactionException;

/**
 * Which Fibers currently hold a transaction on one pooled client, so its
 * root link can refuse a call from a Fiber that has one open.
 *
 * The rule is per Fiber rather than per client because the pool is: a
 * second Fiber running its own queries is served on its own connection
 * and must keep working, while the Fiber holding a transaction would
 * otherwise have its root-link statement land on a different connection,
 * in autocommit, outside the transaction it believes it is in — and stay
 * there after a rollback. A Fiber may hold several transactions at once,
 * each on its own connection, so this counts entries rather than
 * answering yes/no.
 *
 * Owners are held by identity, never by object id: Fiber objects are
 * reused (`Kinetis\Async\FiberPool`), and an id could be recycled while
 * an abandoned transaction still has an entry here.
 *
 * @internal
 */
final class FiberTransactions
{
    /** @var list<?Fiber> One entry per open transaction; null is the main context. */
    private array $owners = [];

    /** Records a transaction for the calling Fiber and returns its owner, for {@see close()}. */
    public function open(): ?Fiber
    {
        $owner = Fiber::getCurrent();
        $this->owners[] = $owner;

        return $owner;
    }

    /** Drops one entry for $owner — the Fiber {@see open()} recorded, whichever Fiber ends the transaction. */
    public function close(?Fiber $owner): void
    {
        $at = \array_search($owner, $this->owners, true);

        if ($at !== false) {
            unset($this->owners[$at]);
            $this->owners = \array_values($this->owners);
        }
    }

    /** @throws TransactionException when the calling Fiber holds a transaction on this client. */
    public function assertNone(): void
    {
        if (!\in_array(Fiber::getCurrent(), $this->owners, true)) {
            return;
        }

        throw new TransactionException(
            'This Fiber has an open transaction on this client: run the statement through the '
            . 'transaction. The client itself would serve it on a different pooled connection, in '
            . 'autocommit, where the transaction can neither commit nor roll it back.',
        );
    }
}
