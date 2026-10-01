<?php

declare(strict_types=1);

namespace Naluz\Cache;

interface Incrementable
{
    /** Increment a counter, creating it with the given TTL (seconds) on first use. Returns the new value. */
    public function increment(string $key, int $ttl): int;
}
