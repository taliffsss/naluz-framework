<?php

declare(strict_types=1);

namespace Naluz\Support;

use Psr\Clock\ClockInterface;

/** PSR-20 clock that only moves when you tell it to — deterministic tests for anything time-based. */
final class FrozenClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct(\DateTimeImmutable|string $now = 'now')
    {
        $this->now = $now instanceof \DateTimeImmutable ? $now : new \DateTimeImmutable($now);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    /** `$clock->advance('+1 hour')` */
    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier) ?: throw new \InvalidArgumentException("Invalid modifier [{$modifier}].");
    }

    public function set(\DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}
