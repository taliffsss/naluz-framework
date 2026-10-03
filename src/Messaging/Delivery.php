<?php

declare(strict_types=1);

namespace Naluz\Messaging;

/** A message handed to a consumer by a broker. Unacknowledged deliveries are redelivered by the broker. */
final class Delivery
{
    private bool $acked = false;

    /** @param \Closure():void $ack */
    public function __construct(
        public readonly string $id,
        public readonly string $topic,
        public readonly string $body,
        private readonly \Closure $ack,
    ) {
    }

    public function ack(): void
    {
        if (!$this->acked) {
            $this->acked = true;
            ($this->ack)();
        }
    }
}
