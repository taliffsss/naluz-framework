<?php

declare(strict_types=1);

namespace Naluz\Messaging\Brokers;

use Naluz\Messaging\Broker;
use Naluz\Messaging\Delivery;

/** In-process broker for tests and local development. Nothing survives the process. */
final class MemoryBroker implements Broker
{
    /** @var array<string,list<array{string,?string}>> topic => log of [body, key] */
    private array $log = [];
    /** @var array<string,int> "group\0topic" => next offset */
    private array $cursor = [];
    /** @var array<string,array<int,true>> "group\0topic" => offsets delivered but not acked */
    private array $pending = [];

    public function publish(string $topic, string $body, ?string $key = null): void
    {
        $this->log[$topic][] = [$body, $key];
    }

    public function declare(array $topics, string $group): void
    {
        foreach ($topics as $topic) {
            // a group sees everything published from the moment it is declared
            $this->cursor[$group . "\0" . $topic] ??= count($this->log[$topic] ?? []);
        }
    }

    public function receive(array $topics, string $group, int $timeoutMs = 1000): ?Delivery
    {
        $this->declare($topics, $group);
        foreach ($topics as $topic) {
            $slot = $group . "\0" . $topic;
            $offset = $this->cursor[$slot];
            if (!isset($this->log[$topic][$offset])) {
                continue;
            }
            $this->cursor[$slot] = $offset + 1;
            $this->pending[$slot][$offset] = true;
            return new Delivery("{$topic}:{$offset}", $topic, $this->log[$topic][$offset][0], function () use ($slot, $offset): void {
                unset($this->pending[$slot][$offset]);
            });
        }
        return null;
    }

    /**
     * Simulate a crashed consumer: everything delivered to `$group` but never acked is delivered again, oldest first
     * (a real broker does this after a visibility timeout or when the connection drops).
     */
    public function requeueUnacked(string $group): void
    {
        foreach ($this->pending as $slot => $offsets) {
            if (str_starts_with($slot, $group . "\0") && $offsets !== []) {
                $this->cursor[$slot] = min(array_keys($offsets));
                $this->pending[$slot] = [];
            }
        }
    }

    /** @return list<string> every body published to `$topic` (for assertions in tests) */
    public function published(string $topic): array
    {
        return array_map(fn ($entry) => $entry[0], $this->log[$topic] ?? []);
    }

    public function key(string $topic, int $index): ?string
    {
        return $this->log[$topic][$index][1] ?? null;
    }
}
