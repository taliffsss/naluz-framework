<?php

declare(strict_types=1);

namespace Naluz\Cache\Psr6;

use Psr\Cache\CacheItemInterface;
use Psr\Clock\ClockInterface;

final class CacheItem implements CacheItemInterface
{
    private ?\DateTimeInterface $expiration = null;

    public function __construct(
        private readonly string $key,
        private mixed $value,
        private readonly bool $hit,
        private readonly ClockInterface $clock,
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->hit ? $this->value : null;
    }

    public function isHit(): bool
    {
        return $this->hit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;
        return $this;
    }

    public function expiresAt(?\DateTimeInterface $expiration): static
    {
        $this->expiration = $expiration;
        return $this;
    }

    public function expiresAfter(int|\DateInterval|null $time): static
    {
        $this->expiration = match (true) {
            $time === null => null,
            $time instanceof \DateInterval => $this->clock->now()->add($time),
            default => $this->clock->now()->modify(($time >= 0 ? '+' : '') . $time . ' seconds'),
        };
        return $this;
    }

    /** @internal a hit-state copy (deferred items must read back as hits) */
    public function asHit(): self
    {
        $copy = new self($this->key, $this->value, true, $this->clock);
        $copy->expiration = $this->expiration;
        return $copy;
    }

    /** @internal seconds until expiry (null = forever, <=0 = already expired) */
    public function ttl(): ?int
    {
        return $this->expiration === null ? null : $this->expiration->getTimestamp() - $this->clock->now()->getTimestamp();
    }

    /** @internal value regardless of hit state (used when saving) */
    public function rawValue(): mixed
    {
        return $this->value;
    }
}
