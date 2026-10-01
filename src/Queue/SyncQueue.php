<?php

declare(strict_types=1);

namespace Naluz\Queue;

use Naluz\Container\Container;

/** Runs jobs immediately, in-process (development/tests). Exceptions propagate to the caller. */
final class SyncQueue implements Queue
{
    public function __construct(private readonly Container $container)
    {
    }

    public function push(Job $job): void
    {
        $this->container->call([$job, 'handle']);
    }

    public function pushRaw(string $payload, string $queue = 'default', int $delay = 0): void
    {
        $this->container->call([$this->container->make(Payload::class)->decode($payload), 'handle']);
    }

    public function pop(string $queue = 'default'): ?QueuedJob
    {
        return null;
    }

    public function size(string $queue = 'default'): int
    {
        return 0;
    }
}
