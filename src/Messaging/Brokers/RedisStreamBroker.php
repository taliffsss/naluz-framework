<?php

declare(strict_types=1);

namespace Naluz\Messaging\Brokers;

use Naluz\Messaging\Broker;
use Naluz\Messaging\Delivery;
use Naluz\Messaging\MessagingException;
use Naluz\Redis\Client;
use Naluz\Redis\RedisException;

/**
 * Redis Streams (Redis 6.2+) through the framework's own dependency-free client: XADD to publish, consumer groups
 * with XREADGROUP/XACK to consume, XAUTOCLAIM to take over messages a crashed consumer never acked.
 */
final class RedisStreamBroker implements Broker
{
    /** @var array<string,true> */
    private array $ready = [];

    public function __construct(
        private readonly Client $redis,
        private readonly string $prefix = 'naluz:stream:',
        private readonly int $maxLength = 100_000,
        private readonly int $visibilityTimeout = 60,
        private readonly string $startId = '0',
        private readonly string $consumer = '',
    ) {
    }

    public function publish(string $topic, string $body, ?string $key = null): void
    {
        $args = ['XADD', $this->stream($topic)];
        if ($this->maxLength > 0) {
            array_push($args, 'MAXLEN', '~', $this->maxLength);
        }
        array_push($args, '*', 'body', $body);
        if ($key !== null) {
            array_push($args, 'key', $key);
        }
        try {
            $this->redis->command(...$args);
        } catch (RedisException $e) {
            throw new MessagingException('Could not publish to Redis stream: ' . $e->getMessage(), 0, $e);
        }
    }

    public function declare(array $topics, string $group): void
    {
        foreach ($topics as $topic) {
            $slot = $group . "\0" . $topic;
            if (isset($this->ready[$slot])) {
                continue;
            }
            try {
                $this->redis->command('XGROUP', 'CREATE', $this->stream($topic), $group, $this->startId, 'MKSTREAM');
            } catch (RedisException $e) {
                if (!str_contains($e->getMessage(), 'BUSYGROUP')) {
                    throw new MessagingException('Could not create Redis consumer group: ' . $e->getMessage(), 0, $e);
                }
            }
            $this->ready[$slot] = true;
        }
    }

    public function receive(array $topics, string $group, int $timeoutMs = 1000): ?Delivery
    {
        $this->declare($topics, $group);
        $consumer = $this->consumer !== '' ? $this->consumer : (gethostname() ?: 'host') . '-' . getmypid();
        try {
            // 1. take over what a dead consumer left pending
            foreach ($topics as $topic) {
                $claimed = $this->redis->command('XAUTOCLAIM', $this->stream($topic), $group, $consumer, $this->visibilityTimeout * 1000, '0-0', 'COUNT', 1);
                $delivery = is_array($claimed) ? $this->delivery($topic, $group, $claimed[1] ?? []) : null;
                if ($delivery !== null) {
                    return $delivery;
                }
            }
            // 2. otherwise the next new message (BLOCK stays under the client's socket timeout)
            $args = ['XREADGROUP', 'GROUP', $group, $consumer, 'COUNT', 1, 'BLOCK', min(max(1, $timeoutMs), 4000), 'STREAMS'];
            foreach ($topics as $topic) {
                $args[] = $this->stream($topic);
            }
            foreach ($topics as $_) {
                $args[] = '>';
            }
            $reply = $this->redis->command(...$args);
        } catch (RedisException $e) {
            throw new MessagingException('Could not read from Redis stream: ' . $e->getMessage(), 0, $e);
        }
        foreach (is_array($reply) ? $reply : [] as [$stream, $entries]) {
            $delivery = $this->delivery(substr((string) $stream, strlen($this->prefix)), $group, $entries);
            if ($delivery !== null) {
                return $delivery;
            }
        }
        return null;
    }

    /** @param array<mixed> $entries */
    private function delivery(string $topic, string $group, array $entries): ?Delivery
    {
        foreach ($entries as [$id, $fields]) {
            $map = [];
            for ($i = 0; is_array($fields) && $i + 1 < count($fields); $i += 2) {
                $map[(string) $fields[$i]] = (string) $fields[$i + 1];
            }
            $stream = $this->stream($topic);
            if (!isset($map['body'])) {
                // the entry was trimmed or deleted while pending: nothing to deliver
                $this->redis->command('XACK', $stream, $group, $id);
                continue;
            }
            return new Delivery((string) $id, $topic, $map['body'], fn () => $this->redis->command('XACK', $stream, $group, $id));
        }
        return null;
    }

    private function stream(string $topic): string
    {
        return $this->prefix . $topic;
    }
}
