<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Driver;

use Kinetis\Persistence\Contract\SqlInstrumentation;
use Throwable;

/**
 * The only way a driver reports a moment. Every call into the wrapped
 * {@see SqlInstrumentation} is contained here, because each one sits
 * inside a driver's own try/catch/finally: a failure escaping would
 * replace a query's result or failure, a transaction's outcome, or the
 * release of its connection. A started moment that fails yields null,
 * which its ended moment receives like any other token. Without a
 * wrapped instrumentation every moment is a no-op.
 *
 * A contained failure is reported once through error_log(), naming the
 * moment, the instrumentation class and the exception class — never the
 * exception's message or the moment's arguments, either of which can
 * carry SQL text.
 *
 * @internal
 */
final readonly class ContainedSqlInstrumentation implements SqlInstrumentation
{
    public function __construct(
        private ?SqlInstrumentation $instrumentation = null,
    ) {}

    #[\Override]
    public function queryDispatched(string $system, string $sql): mixed
    {
        if ($this->instrumentation === null) {
            return null;
        }

        try {
            return $this->instrumentation->queryDispatched($system, $sql);
        } catch (Throwable $e) {
            $this->report('queryDispatched', $e);

            return null;
        }
    }

    #[\Override]
    public function queryServerStarted(mixed $token): void
    {
        try {
            $this->instrumentation?->queryServerStarted($token);
        } catch (Throwable $e) {
            $this->report('queryServerStarted', $e);
        }
    }

    #[\Override]
    public function queryReaped(mixed $token, ?Throwable $failure): void
    {
        try {
            $this->instrumentation?->queryReaped($token, $failure);
        } catch (Throwable $e) {
            $this->report('queryReaped', $e);
        }
    }

    #[\Override]
    public function transactionStarted(string $system): mixed
    {
        if ($this->instrumentation === null) {
            return null;
        }

        try {
            return $this->instrumentation->transactionStarted($system);
        } catch (Throwable $e) {
            $this->report('transactionStarted', $e);

            return null;
        }
    }

    #[\Override]
    public function transactionEnded(mixed $token, string $outcome): void
    {
        try {
            $this->instrumentation?->transactionEnded($token, $outcome);
        } catch (Throwable $e) {
            $this->report('transactionEnded', $e);
        }
    }

    private function report(string $moment, Throwable $failure): void
    {
        try {
            \error_log(\sprintf(
                'Kinetis SQL instrumentation moment "%s" failed on %s (%s)',
                $moment,
                \get_debug_type($this->instrumentation),
                $failure::class,
            ));
        } catch (Throwable) {
            // Discarded: the diagnostic must not become a failure itself.
        }
    }
}
