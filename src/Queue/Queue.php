<?php

declare(strict_types=1);

namespace Naluz\Queue;

interface Queue
{
    public function push(Job $job): void;

    /** Re-enqueue an already-encoded payload (used by retries). */
    public function pushRaw(string $payload, string $queue = 'default', int $delay = 0): void;

    /** Reserve the next available job, or null. */
    public function pop(string $queue = 'default'): ?QueuedJob;

    public function size(string $queue = 'default'): int;
}
