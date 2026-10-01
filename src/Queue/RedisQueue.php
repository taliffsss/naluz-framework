<?php

declare(strict_types=1);

namespace Naluz\Queue;

use Naluz\Redis\Client;

/**
 * Redis queue: a ready list, a delayed sorted set and a reserved sorted set (visibility timeout), manipulated
 * atomically with a Lua script so concurrent workers never double-process a job.
 */
final class RedisQueue implements Queue
{
    private const POP = <<<'LUA'
        local due = redis.call('ZRANGEBYSCORE', KEYS[2], '-inf', ARGV[1])
        for _, j in ipairs(due) do redis.call('ZREM', KEYS[2], j) redis.call('RPUSH', KEYS[1], j) end
        local expired = redis.call('ZRANGEBYSCORE', KEYS[3], '-inf', ARGV[1])
        for _, j in ipairs(expired) do redis.call('ZREM', KEYS[3], j) redis.call('RPUSH', KEYS[1], j) end
        local job = redis.call('LPOP', KEYS[1])
        if not job then return false end
        local env = cjson.decode(job)
        env['attempts'] = env['attempts'] + 1
        local reserved = cjson.encode(env)
        redis.call('ZADD', KEYS[3], ARGV[2], reserved)
        return reserved
        LUA;

    public function __construct(private readonly Client $redis, private readonly Payload $payloads, private readonly int $retryAfter = 90, private readonly string $prefix = 'naluz:queue:')
    {
    }

    private function key(string $queue, string $part = ''): string
    {
        return $this->prefix . $queue . $part;
    }

    public function push(Job $job): void
    {
        $this->pushRaw($this->payloads->encode($job), $job->queueName(), $job->delaySeconds());
    }

    public function pushRaw(string $payload, string $queue = 'default', int $delay = 0, int $attempts = 0): void
    {
        $envelope = json_encode(['id' => bin2hex(random_bytes(8)), 'attempts' => $attempts, 'payload' => $payload], JSON_THROW_ON_ERROR);
        $delay > 0
            ? $this->redis->command('ZADD', $this->key($queue, ':delayed'), time() + $delay, $envelope)
            : $this->redis->command('RPUSH', $this->key($queue), $envelope);
    }

    public function pop(string $queue = 'default'): ?QueuedJob
    {
        $now = time();
        $raw = $this->redis->command(
            'EVAL',
            self::POP,
            3,
            $this->key($queue),
            $this->key($queue, ':delayed'),
            $this->key($queue, ':reserved'),
            $now,
            $now + $this->retryAfter
        );
        if (!is_string($raw)) {
            return null;
        }
        $env = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        $reservedKey = $this->key($queue, ':reserved');
        return new QueuedJob(
            (string) $env['id'],
            $queue,
            (string) $env['payload'],
            (int) $env['attempts'],
            fn () => $this->redis->command('ZREM', $reservedKey, $raw),
            function (int $delay) use ($reservedKey, $raw, $env, $queue): void {
                $this->redis->command('ZREM', $reservedKey, $raw);
                $this->pushRaw((string) $env['payload'], $queue, $delay, (int) $env['attempts']);
            }
        );
    }

    public function size(string $queue = 'default'): int
    {
        return (int) $this->redis->command('LLEN', $this->key($queue))
            + (int) $this->redis->command('ZCARD', $this->key($queue, ':delayed'))
            + (int) $this->redis->command('ZCARD', $this->key($queue, ':reserved'));
    }
}
