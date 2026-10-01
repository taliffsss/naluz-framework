<?php

declare(strict_types=1);

namespace Naluz\Queue;

/** A job reserved from a queue, with the operations a worker needs. Created by queue drivers. */
final class QueuedJob
{
    private bool $done = false;

    /**
     * @param \Closure():void $delete
     * @param \Closure(int):void $release
     */
    public function __construct(
        public readonly string|int $id,
        public readonly string $queue,
        public readonly string $payload,
        public readonly int $attempts,
        private readonly \Closure $delete,
        private readonly \Closure $release,
    ) {
    }

    public function delete(): void
    {
        if (!$this->done) {
            $this->done = true;
            ($this->delete)();
        }
    }

    public function release(int $delay = 0): void
    {
        if (!$this->done) {
            $this->done = true;
            ($this->release)($delay);
        }
    }
}
