<?php

declare(strict_types=1);

namespace Naluz\Cache;

use Psr\SimpleCache\CacheInterface;

class ArrayCache implements CacheInterface, Incrementable
{
    /** @var array<string,array{0:mixed,1:?int}> */
    protected array $store = [];

    public function get(string $key, mixed $default = null): mixed
    {
        $this->assertKey($key);
        $entry = $this->read($key);
        return $entry === null ? $default : $entry[0];
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->assertKey($key);
        return $this->write($key, $value, self::expiry($ttl));
    }

    public function delete(string $key): bool
    {
        $this->assertKey($key);
        unset($this->store[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->store = [];
        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get($key, $default);
        }
        return $out;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $k => $v) {
            $this->set((string) $k, $v, $ttl);
        }
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    public function has(string $key): bool
    {
        $this->assertKey($key);
        return $this->read($key) !== null;
    }

    /** Atomically-enough counter used by the rate limiter. */
    public function increment(string $key, int $ttl): int
    {
        $entry = $this->read($key);
        $value = ($entry[0] ?? 0) + 1;
        $this->write($key, $value, $entry[1] ?? time() + $ttl);
        return $value;
    }

    /** @return array{0:mixed,1:?int}|null */
    protected function read(string $key): ?array
    {
        $entry = $this->store[$key] ?? null;
        if ($entry !== null && $entry[1] !== null && $entry[1] <= time()) {
            unset($this->store[$key]);
            return null;
        }
        return $entry;
    }

    protected function write(string $key, mixed $value, ?int $expires): bool
    {
        $this->store[$key] = [$value, $expires];
        return true;
    }

    protected static function expiry(null|int|\DateInterval $ttl): ?int
    {
        return match (true) {
            $ttl === null => null,
            $ttl instanceof \DateInterval => (new \DateTimeImmutable())->add($ttl)->getTimestamp(),
            default => time() + $ttl,
        };
    }

    protected function assertKey(string $key): void
    {
        if ($key === '' || preg_match('/[{}()\/\\\\@:]/', $key)) {
            throw new InvalidKeyException("Invalid cache key [{$key}].");
        }
    }
}
