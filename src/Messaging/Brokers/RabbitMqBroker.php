<?php

declare(strict_types=1);

namespace Naluz\Messaging\Brokers;

use Naluz\Messaging\Broker;
use Naluz\Messaging\Delivery;
use Naluz\Messaging\MessagingException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * RabbitMQ over php-amqplib (`composer require php-amqplib/php-amqplib`). Events go to one durable topic exchange
 * with the topic as routing key; each consumer group gets a durable queue `<group>.<topic>` bound to it, so groups
 * fan out and members of a group share work. Topics you subscribe to may use AMQP wildcards (`orders.*`, `orders.#`).
 */
final class RabbitMqBroker implements Broker
{
    private ?AMQPChannel $channel = null;
    private bool $exchangeReady = false;
    /** @var array<string,true> */
    private array $queues = [];

    /**
     * @param \Closure():AMQPChannel $channelFactory opens a connection and returns a channel
     * @param array<string,mixed> $queueArguments e.g. ['x-queue-type' => ['S', 'quorum']]
     */
    public function __construct(
        private readonly \Closure $channelFactory,
        private readonly string $exchange = 'naluz.events',
        private readonly int $prefetch = 1,
        private readonly array $queueArguments = [],
    ) {
    }

    public function publish(string $topic, string $body, ?string $key = null): void
    {
        $properties = ['content_type' => 'application/json', 'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT];
        if ($key !== null) {
            $properties['correlation_id'] = $key;
        }
        try {
            $this->channel()->basic_publish(new AMQPMessage($body, $properties), $this->exchange, $topic);
        } catch (AMQPExceptionInterface $e) {
            $this->reset();
            throw new MessagingException('Could not publish to RabbitMQ: ' . $e->getMessage(), 0, $e);
        }
    }

    public function declare(array $topics, string $group): void
    {
        try {
            $channel = $this->channel();
            foreach ($topics as $topic) {
                $queue = $this->queue($group, $topic);
                if (isset($this->queues[$queue])) {
                    continue;
                }
                $channel->queue_declare($queue, false, true, false, false, false, $this->queueArguments);
                $channel->queue_bind($queue, $this->exchange, $topic);
                $this->queues[$queue] = true;
            }
        } catch (AMQPExceptionInterface $e) {
            $this->reset();
            throw new MessagingException('Could not declare RabbitMQ queue: ' . $e->getMessage(), 0, $e);
        }
    }

    public function receive(array $topics, string $group, int $timeoutMs = 1000): ?Delivery
    {
        $this->declare($topics, $group);
        $deadline = microtime(true) + $timeoutMs / 1000;
        try {
            do {
                foreach ($topics as $topic) {
                    $message = $this->channel()->basic_get($this->queue($group, $topic), false);
                    if ($message instanceof AMQPMessage) {
                        $tag = $message->getDeliveryTag();
                        $channel = $this->channel();
                        return new Delivery((string) $tag, (string) ($message->getRoutingKey() ?: $topic), $message->getBody(), fn () => $channel->basic_ack($tag));
                    }
                }
                usleep(50_000);
            } while (microtime(true) < $deadline);
        } catch (AMQPExceptionInterface $e) {
            $this->reset();
            throw new MessagingException('Could not read from RabbitMQ: ' . $e->getMessage(), 0, $e);
        }
        return null;
    }

    private function queue(string $group, string $topic): string
    {
        return $group . '.' . $topic;
    }

    private function channel(): AMQPChannel
    {
        if ($this->channel === null) {
            $this->channel = ($this->channelFactory)();
            $this->channel->basic_qos(0, $this->prefetch, false);
        }
        if (!$this->exchangeReady) {
            $this->channel->exchange_declare($this->exchange, 'topic', false, true, false);
            $this->exchangeReady = true;
        }
        return $this->channel;
    }

    /** Forget the channel after a failure so the next call reconnects (queues are re-declared; that is idempotent). */
    private function reset(): void
    {
        $this->channel = null;
        $this->exchangeReady = false;
        $this->queues = [];
    }
}
