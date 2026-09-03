<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Tests\Fixtures;

use Kinetis\Queue\Job;
use Kinetis\Queue\JobSerializer;
use Kinetis\Queue\QueuedJob;
use Kinetis\Queue\QueueInterface;

/**
 * A minimal, single-slot QueueInterface — this suite only ever needs to
 * push exactly one job and have QueueWorker::processNext() pop it once,
 * so there is no reason to duplicate kinetis/queue's own richer
 * InMemoryQueue fixture (unreachable here regardless — a dependency's
 * autoload-dev mapping is never available to a consumer package, see
 * InMemoryLogger's own docblock for the same note).
 */
final class SingleJobQueue implements QueueInterface
{
    /** @var array{class: class-string<Job>, args: array<string, mixed>}|null */
    private ?array $pending = null;

    /** @var list<mixed> */
    public array $acked = [];

    /** @var list<mixed> */
    public array $released = [];

    /** @var list<mixed> */
    public array $failed = [];

    public function push(Job $job, int $delaySeconds = 0, string $queue = 'default', ?int $maxAttempts = null): void
    {
        $this->pending = JobSerializer::serialize($job);
    }

    public function pop(int $timeoutSeconds = 0, array $queues = ['default']): ?QueuedJob
    {
        if ($this->pending === null) {
            return null;
        }

        $job = new QueuedJob($this->pending['class'], $this->pending['args'], handle: 1, queue: $queues[0] ?? 'default');
        $this->pending = null;

        return $job;
    }

    public function ack(QueuedJob $job): void
    {
        $this->acked[] = $job->handle;
    }

    public function release(QueuedJob $job): void
    {
        $this->released[] = $job->handle;
    }

    public function fail(QueuedJob $job): void
    {
        $this->failed[] = $job->handle;
    }

    public function size(string $queue = 'default'): int
    {
        return $this->pending === null ? 0 : 1;
    }

    public function clear(string $queue = 'default'): int
    {
        $size = $this->size($queue);
        $this->pending = null;

        return $size;
    }
}
