<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Fixtures;

use Kinetis\Persistence\Contract\SqlInstrumentation;
use Throwable;

/**
 * An instrumentation that records every moment in the order it was
 * reported, along with what each one carried — the counterpart to
 * {@see ThrowingSqlInstrumentation}, which proves a failing
 * implementation changes nothing.
 *
 * The token is a distinct string per started moment, so a case can prove
 * an ended moment was joined to its own start rather than to whichever
 * one happened to be last.
 */
final class RecordingSqlInstrumentation implements SqlInstrumentation
{
    /** @var list<string> Every moment, in order. */
    public array $moments = [];

    /** @var list<array{string, string}> The $system and SQL each queryDispatched() carried. */
    public array $dispatched = [];

    /** @var list<array{mixed, ?Throwable}> The token and failure each queryReaped() carried. */
    public array $reaped = [];

    /** @var list<array{mixed, string}> The token and outcome each transactionEnded() carried. */
    public array $ended = [];

    private int $tokens = 0;

    #[\Override]
    public function queryDispatched(string $system, string $sql): mixed
    {
        $this->moments[] = 'queryDispatched';
        $this->dispatched[] = [$system, $sql];

        return 'query-' . ++$this->tokens;
    }

    #[\Override]
    public function queryServerStarted(mixed $token): void
    {
        $this->moments[] = 'queryServerStarted';
    }

    #[\Override]
    public function queryReaped(mixed $token, ?Throwable $failure): void
    {
        $this->moments[] = 'queryReaped';
        $this->reaped[] = [$token, $failure];
    }

    #[\Override]
    public function transactionStarted(string $system): mixed
    {
        $this->moments[] = 'transactionStarted';

        return 'transaction-' . ++$this->tokens;
    }

    #[\Override]
    public function transactionEnded(mixed $token, string $outcome): void
    {
        $this->moments[] = 'transactionEnded';
        $this->ended[] = [$token, $outcome];
    }
}
