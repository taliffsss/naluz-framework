<?php

declare(strict_types=1);

namespace Naluz\Queue;

use Naluz\Config\Repository;
use Naluz\Container\Container;
use Naluz\Database\Connection;
use Naluz\Redis\Client;

/** Resolves the configured queue connection and dispatches jobs onto it. */
final class QueueManager
{
    /** @var array<string,Queue> */
    private array $connections = [];

    public function __construct(private readonly Container $container, private readonly Repository $config)
    {
    }

    public function connection(?string $name = null): Queue
    {
        $name ??= (string) $this->config->get('queue.default', 'sync');
        return $this->connections[$name] ??= $this->make($name);
    }

    public function extend(string $name, Queue $queue): void
    {
        $this->connections[$name] = $queue;
    }

    public function dispatch(Job $job): void
    {
        $this->connection()->push($job);
    }

    private function make(string $name): Queue
    {
        $retryAfter = (int) $this->config->get('queue.retry_after', 90);
        return match ($name) {
            'sync' => new SyncQueue($this->container),
            'database' => new DatabaseQueue($this->container->make(Connection::class), $this->container->make(Payload::class), $retryAfter),
            'redis' => new RedisQueue($this->container->make(Client::class), $this->container->make(Payload::class), $retryAfter),
            default => throw new \InvalidArgumentException("Queue connection [{$name}] is not supported."),
        };
    }
}
