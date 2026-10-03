<?php

declare(strict_types=1);

namespace Naluz\Messaging;

/**
 * Handles messages for the topics it is registered for in config/messaging.php (`subscribers`).
 * Delivery is at-least-once, so `handle()` must be idempotent. Throw to have the message retried, then dead-lettered.
 */
interface Subscriber
{
    public function handle(Message $message): void;
}
