<?php

declare(strict_types=1);

namespace Naluz\Cache\Psr6;

use Naluz\Support\SystemClock;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-6 pool on top of any PSR-16 cache (file, array, Redis…), so libraries that want
 * `CacheItemPoolInterface` can use the application's configured cache.
 */
final class CacheItemPool implements CacheItemPoolInterface
{
    /** @var array<string,CacheItem> */
    private array $deferred = [];
    private readonly ClockInterface $clock;

    public function __construct(private readonly CacheInterface $cache, ?ClockInterface $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    private function validate(string $key): void
    {
        // PSR-6: at least A-Z a-z 0-9 _ . must be supported; {}()/\@: are reserved.
        if ($key === '' || strlen($key) > 64 || !preg_match('/^[A-Za-z0-9_.]+$/', $key)) {
            throw new InvalidArgumentException("Invalid cache key [{$key}].");
        }
    }

    public function getItem(string $key): CacheItemInterface
    {
        $this->validate($key);
        if (isset($this->deferred[$key])) {
            return $this->deferred[$key]->asHit();
        }
        $stored = $this->cache->get($key);
        // values are wrapped so that a stored `null`/`false` is still a hit
        return is_array($stored) && array_key_exists('v', $stored) && count($stored) === 1
            ? new CacheItem($key, $stored['v'], true, $this->clock)
            : new CacheItem($key, null, false, $this->clock);
    }

    public function getItems(array $keys = []): iterable
    {
        $items = [];
        foreach ($keys as $key) {
            $items[$key] = $this->getItem($key);
        }
        return $items;
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    public function clear(): bool
    {
        $this->deferred = [];
        return $this->cache->clear();
    }

    public function deleteItem(string $key): bool
    {
        $this->validate($key);
        unset($this->deferred[$key]);
        return $this->cache->delete($key);
    }

    public function deleteItems(array $keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            $ok = $this->deleteItem($key) && $ok;
        }
        return $ok;
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof CacheItem) {
            throw new InvalidArgumentException('This pool only saves items it created.');
        }
        $this->validate($item->getKey());
        $ttl = $item->ttl();
        if ($ttl !== null && $ttl <= 0) {
            return $this->cache->delete($item->getKey()); // already expired
        }
        return $this->cache->set($item->getKey(), ['v' => $item->rawValue()], $ttl);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        if (!$item instanceof CacheItem) {
            throw new InvalidArgumentException('This pool only saves items it created.');
        }
        $this->validate($item->getKey());
        $this->deferred[$item->getKey()] = clone $item;
        return true;
    }

    public function commit(): bool
    {
        $ok = true;
        foreach ($this->deferred as $item) {
            $ok = $this->save($item) && $ok;
        }
        $this->deferred = [];
        return $ok;
    }

    public function __destruct()
    {
        $this->commit(); // PSR-6: deferred items must be persisted at the latest on destruction
    }
}
