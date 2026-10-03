<?php

declare(strict_types=1);

namespace Naluz\Messaging;

/** A domain event that knows which topic it belongs on: `EventBus::dispatch(new OrderPlaced(...))`. */
interface PublishableEvent
{
    public function topic(): string;

    /** @return array<string,mixed> JSON-serialisable data; never put secrets in it. */
    public function payload(): array;

    /** Optional partition / ordering key (Kafka); events with the same key keep their order. */
    public function key(): ?string;
}
