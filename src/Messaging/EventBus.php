<?php

declare(strict_types=1);

namespace Naluz\Messaging;

use Naluz\Config\Repository;

/** Publishes events to the configured message broker: `$bus->publish('orders.placed', ['order_id' => 7])`. */
final class EventBus
{
    private const TOPIC = '/^[A-Za-z0-9._-]{1,200}$/';

    public function __construct(private readonly BrokerManager $brokers, private readonly Codec $codec, private readonly Repository $config)
    {
    }

    /**
     * @param array<string,mixed> $payload JSON-serialisable; never put secrets in it
     * @param array<string,string> $headers
     * @param string|null $key partition / ordering key
     */
    public function publish(string $topic, array $payload, array $headers = [], ?string $key = null, ?string $connection = null, string $type = ''): Message
    {
        $message = new Message(bin2hex(random_bytes(8)), self::topic($topic), $type !== '' ? $type : $topic, $payload, $headers, time());
        $this->send($message, $key, $connection);
        return $message;
    }

    public function dispatch(PublishableEvent $event, ?string $connection = null): Message
    {
        return $this->publish($event->topic(), $event->payload(), [], $event->key(), $connection, $event::class);
    }

    /** Re-publish an existing message (retries, dead letters). */
    public function send(Message $message, ?string $key = null, ?string $connection = null): void
    {
        $this->brokers->connection($connection)->publish(self::topic($message->topic), $this->codec->encode($message), $key);
    }

    public static function topic(string $topic): string
    {
        if (preg_match(self::TOPIC, $topic) !== 1) {
            throw new \InvalidArgumentException('Topic names may only contain letters, digits, ".", "_" and "-" (max 200).');
        }
        return $topic;
    }
}
