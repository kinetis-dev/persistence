<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Fixtures;

use Kinetis\Persistence\Contract\SqlInstrumentation;
use RuntimeException;
use Throwable;

/**
 * An instrumentation failing at every moment, counting each attempt so a
 * case can tell the driver did report — and carried on regardless.
 */
final class ThrowingSqlInstrumentation implements SqlInstrumentation
{
    public const string MESSAGE = 'instrumentation failed: SELECT secret FROM vault';

    /** @var list<string> */
    public array $moments = [];

    #[\Override]
    public function queryDispatched(string $system, string $sql): mixed
    {
        $this->moments[] = 'queryDispatched';

        throw new RuntimeException(self::MESSAGE);
    }

    #[\Override]
    public function queryServerStarted(mixed $token): void
    {
        $this->moments[] = 'queryServerStarted';

        throw new RuntimeException(self::MESSAGE);
    }

    #[\Override]
    public function queryReaped(mixed $token, ?Throwable $failure): void
    {
        $this->moments[] = 'queryReaped';

        throw new RuntimeException(self::MESSAGE);
    }

    #[\Override]
    public function transactionStarted(string $system): mixed
    {
        $this->moments[] = 'transactionStarted';

        throw new RuntimeException(self::MESSAGE);
    }

    #[\Override]
    public function transactionEnded(mixed $token, string $outcome): void
    {
        $this->moments[] = 'transactionEnded';

        throw new RuntimeException(self::MESSAGE);
    }
}
