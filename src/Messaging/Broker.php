<?php

declare(strict_types=1);

namespace Naluz\Messaging;

/**
 * A message broker driver. Bodies are opaque strings (see Codec); drivers only move bytes and guarantee
 * at-least-once delivery: a delivery that is not acked is delivered again. Each consumer `group` receives its own
 * copy of every message (fan-out between groups, load-balancing inside one).
 */
interface Broker
{
    /** @throws MessagingException */
    public function publish(string $topic, string $body, ?string $key = null): void;

    /**
     * Create whatever a group needs to receive `topics` (consumer group, queue + binding). `receive()` does this
     * lazily, but a producer that publishes before any consumer has ever run needs it done up front (RabbitMQ
     * drops messages nobody is bound to).
     *
     * @param list<string> $topics
     */
    public function declare(array $topics, string $group): void;

    /**
     * Wait up to `$timeoutMs` for the next message on any of `$topics`.
     *
     * @param list<string> $topics
     */
    public function receive(array $topics, string $group, int $timeoutMs = 1000): ?Delivery;
}
