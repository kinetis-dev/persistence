<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Fixtures;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Duplicated from kinetis/kinetis's own tests/Fixtures/InMemoryLogger.php
 * — a dependency's autoload-dev mapping is never available to a consumer
 * package regardless of path-repo vs. registry install, so this package's
 * own test suite needs its own copy rather than reaching into core's.
 */
final class InMemoryLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
