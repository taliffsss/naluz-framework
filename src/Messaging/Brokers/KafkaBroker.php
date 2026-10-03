<?php

declare(strict_types=1);

namespace Naluz\Messaging\Brokers;

use Naluz\Messaging\Broker;
use Naluz\Messaging\Delivery;
use Naluz\Messaging\MessagingException;

/**
 * Apache Kafka over ext-rdkafka (librdkafka). Idempotent, `acks=all` producer; consumers join a consumer group with
 * auto-commit off and commit an offset only after the message was handled, so a crash redelivers it.
 * Messages with the same key stay ordered (same partition). Kafka topics are created by the broker or by you
 * (see KAFKA auto-create settings); `declare()` has nothing to do.
 */
final class KafkaBroker implements Broker
{
    private const NO_ERROR = 0;
    private const PARTITION_EOF = -191;
    private const TIMED_OUT = -185;

    private ?object $producer = null;
    /** @var array<string,object> group => KafkaConsumer */
    private array $consumers = [];
    /** @var array<string,string> group => subscribed topic list */
    private array $subscribed = [];

    /**
     * @param array<string,string> $config raw librdkafka settings, e.g. metadata.broker.list, security.protocol, sasl.*
     * @param (\Closure(array<string,string>):object)|null $producerFactory returns an object shaped like RdKafka\Producer
     * @param (\Closure(array<string,string>):object)|null $consumerFactory returns an object shaped like RdKafka\KafkaConsumer
     */
    public function __construct(
        private readonly array $config,
        private readonly string $offsetReset = 'earliest',
        private readonly int $flushTimeoutMs = 10_000,
        private readonly ?\Closure $producerFactory = null,
        private readonly ?\Closure $consumerFactory = null,
    ) {
    }

    public function publish(string $topic, string $body, ?string $key = null): void
    {
        $producer = $this->producer ??= $this->makeProducer();
        $producer->newTopic($topic)->produce(\defined('RD_KAFKA_PARTITION_UA') ? RD_KAFKA_PARTITION_UA : -1, 0, $body, $key);
        $producer->poll(0);
        if ($producer->flush($this->flushTimeoutMs) !== self::NO_ERROR) {
            throw new MessagingException('Kafka did not confirm the message within ' . $this->flushTimeoutMs . ' ms.');
        }
    }

    public function declare(array $topics, string $group): void
    {
        // topics are broker-side; the consumer group is created by joining it in receive()
    }

    public function receive(array $topics, string $group, int $timeoutMs = 1000): ?Delivery
    {
        $consumer = $this->consumer($topics, $group);
        $message = $consumer->consume(max(1, $timeoutMs));
        if ($message->err === self::PARTITION_EOF || $message->err === self::TIMED_OUT) {
            return null;
        }
        if ($message->err !== self::NO_ERROR) {
            throw new MessagingException('Kafka consume error: ' . (method_exists($message, 'errstr') ? $message->errstr() : (string) $message->err));
        }
        return new Delivery("{$message->partition}:{$message->offset}", (string) $message->topic_name, (string) $message->payload, fn () => $consumer->commit($message));
    }

    /** @param list<string> $topics */
    private function consumer(array $topics, string $group): object
    {
        $consumer = $this->consumers[$group] ??= $this->makeConsumer($group);
        sort($topics);
        $list = implode(',', $topics);
        if (($this->subscribed[$group] ?? null) !== $list) {
            $consumer->subscribe($topics);
            $this->subscribed[$group] = $list;
        }
        return $consumer;
    }

    private function makeProducer(): object
    {
        $settings = ['enable.idempotence' => 'true', 'acks' => 'all', ...$this->config];
        if ($this->producerFactory !== null) {
            return ($this->producerFactory)($settings);
        }
        $this->requireExtension();
        $conf = new \RdKafka\Conf();
        foreach ($settings as $k => $v) {
            $conf->set((string) $k, (string) $v);
        }
        return new \RdKafka\Producer($conf);
    }

    private function makeConsumer(string $group): object
    {
        $settings = ['auto.offset.reset' => $this->offsetReset, ...$this->config, 'group.id' => $group, 'enable.auto.commit' => 'false'];
        if ($this->consumerFactory !== null) {
            return ($this->consumerFactory)($settings);
        }
        $this->requireExtension();
        $conf = new \RdKafka\Conf();
        foreach ($settings as $k => $v) {
            $conf->set((string) $k, (string) $v);
        }
        return new \RdKafka\KafkaConsumer($conf);
    }

    private function requireExtension(): void
    {
        if (!extension_loaded('rdkafka')) {
            throw new MessagingException('The Kafka driver needs ext-rdkafka (pecl install rdkafka).');
        }
    }
}
