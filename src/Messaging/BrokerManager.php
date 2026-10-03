<?php

declare(strict_types=1);

namespace Naluz\Messaging;

use Naluz\Config\Repository;
use Naluz\Container\Container;
use Naluz\Messaging\Brokers\KafkaBroker;
use Naluz\Messaging\Brokers\MemoryBroker;
use Naluz\Messaging\Brokers\RabbitMqBroker;
use Naluz\Messaging\Brokers\RedisStreamBroker;
use Naluz\Redis\Client;

/** Resolves a messaging connection from config/messaging.php. Nothing connects until it is first used. */
final class BrokerManager
{
    /** @var array<string,Broker> */
    private array $connections = [];

    public function __construct(private readonly Container $container, private readonly Repository $config)
    {
    }

    public function connection(?string $name = null): Broker
    {
        $name ??= (string) $this->config->get('messaging.default', 'memory');
        return $this->connections[$name] ??= $this->make($name);
    }

    public function extend(string $name, Broker $broker): void
    {
        $this->connections[$name] = $broker;
    }

    private function make(string $name): Broker
    {
        $cfg = (array) $this->config->get("messaging.connections.{$name}", []);
        $driver = (string) ($cfg['driver'] ?? $name);
        return match ($driver) {
            'memory' => new MemoryBroker(),
            'redis' => $this->redis($cfg),
            'rabbitmq' => $this->rabbitmq($cfg),
            'kafka' => $this->kafka($cfg),
            default => throw new \InvalidArgumentException("Messaging connection [{$name}] is not supported (use memory, redis, rabbitmq or kafka)."),
        };
    }

    /** @param array<string,mixed> $cfg */
    private function redis(array $cfg): Broker
    {
        // `host` in the connection overrides config/redis.php; otherwise the shared client is used
        $client = isset($cfg['host']) ? Client::fromConfig($cfg) : $this->container->make(Client::class);
        return new RedisStreamBroker(
            $client,
            (string) ($cfg['prefix'] ?? 'naluz:stream:'),
            (int) ($cfg['max_length'] ?? 100_000),
            (int) ($cfg['visibility_timeout'] ?? 60),
            (string) ($cfg['start_id'] ?? '0'),
            (string) ($cfg['consumer'] ?? ''),
        );
    }

    /** @param array<string,mixed> $cfg */
    private function rabbitmq(array $cfg): Broker
    {
        if (!class_exists(\PhpAmqpLib\Connection\AMQPStreamConnection::class)) {
            throw new MessagingException('The RabbitMQ driver needs php-amqplib: composer require php-amqplib/php-amqplib');
        }
        $channel = function () use ($cfg) {
            $args = [
                (string) ($cfg['host'] ?? '127.0.0.1'),
                (int) ($cfg['port'] ?? 5672),
                (string) ($cfg['user'] ?? 'guest'),
                (string) ($cfg['password'] ?? 'guest'),
                (string) ($cfg['vhost'] ?? '/'),
            ];
            $connection = ($cfg['ssl'] ?? false)
                ? new \PhpAmqpLib\Connection\AMQPSSLConnection(...$args, ssl_options: (array) ($cfg['ssl_options'] ?? ['verify_peer' => true, 'verify_peer_name' => true]))
                : new \PhpAmqpLib\Connection\AMQPStreamConnection(...$args);
            return $connection->channel();
        };
        return new RabbitMqBroker($channel, (string) ($cfg['exchange'] ?? 'naluz.events'), (int) ($cfg['prefetch'] ?? 1), (array) ($cfg['queue_arguments'] ?? []));
    }

    /** @param array<string,mixed> $cfg */
    private function kafka(array $cfg): Broker
    {
        $settings = ['metadata.broker.list' => (string) ($cfg['brokers'] ?? '127.0.0.1:9092'), ...array_map('strval', (array) ($cfg['options'] ?? []))];
        return new KafkaBroker($settings, (string) ($cfg['offset_reset'] ?? 'earliest'), (int) ($cfg['flush_timeout_ms'] ?? 10_000));
    }
}
