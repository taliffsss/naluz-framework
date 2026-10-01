<?php

declare(strict_types=1);

namespace Naluz\Support;

use Psr\Clock\ClockInterface;

/** PSR-20 clock backed by the system time, in the application's timezone. */
final class SystemClock implements ClockInterface
{
    public function __construct(private readonly ?\DateTimeZone $timezone = null)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $this->timezone ?? new \DateTimeZone(date_default_timezone_get()));
    }
}
