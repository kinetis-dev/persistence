<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Fixtures;

use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * Records every call like InMemoryLogger, but can be told to throw from
 * warning()/error() — proving TransactionGuard's cleanup keeps working
 * even when the logger it reports through is itself broken. Records the
 * call before throwing, so a test can still see what was attempted.
 */
final class ThrowingLogger extends AbstractLogger
{
    public bool $throwOnWarning = false;

    public bool $throwOnError = false;

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];

        if ($level === 'warning' && $this->throwOnWarning) {
            throw new RuntimeException('The logger itself failed while reporting a warning.');
        }

        if ($level === 'error' && $this->throwOnError) {
            throw new RuntimeException('The logger itself failed while reporting an error.');
        }
    }
}
