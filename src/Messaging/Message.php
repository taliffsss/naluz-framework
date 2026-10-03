<?php

declare(strict_types=1);

namespace Naluz\Messaging;

/** An event as seen by subscribers. `type` is informational (e.g. the event class); it is never instantiated. */
final class Message
{
    public const ATTEMPTS = 'x-attempts';
    public const TARGET_GROUP = 'x-target-group';

    /**
     * @param array<string,mixed> $payload
     * @param array<string,string> $headers
     */
    public function __construct(
        public readonly string $id,
        public readonly string $topic,
        public readonly string $type,
        public readonly array $payload,
        public readonly array $headers = [],
        public readonly int $timestamp = 0,
    ) {
    }

    /** How many times delivery has been attempted for the consuming group (1 on the first try). */
    public function attempts(): int
    {
        return max(1, (int) ($this->headers[self::ATTEMPTS] ?? 1));
    }

    /** @param array<string,string> $headers */
    public function withHeaders(array $headers): self
    {
        return new self($this->id, $this->topic, $this->type, $this->payload, [...$this->headers, ...$headers], $this->timestamp);
    }

    public function withTopic(string $topic): self
    {
        return new self($this->id, $topic, $this->type, $this->payload, $this->headers, $this->timestamp);
    }
}
