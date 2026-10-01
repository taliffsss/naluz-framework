<?php

declare(strict_types=1);

namespace Naluz\Redis;

use Naluz\Cache\ArrayCache;
use Naluz\Cache\Incrementable;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 cache on Redis. Values are PHP-serialised and read back with `allowed_classes => false`,
 * so a compromised Redis cannot be used for object-injection attacks.
 */
final class RedisCache extends ArrayCache implements CacheInterface, Incrementable
{
    public function __construct(private readonly Client $redis, private readonly string $prefix = 'naluz:cache:')
    {
    }

    protected function read(string $key): ?array
    {
        $raw = $this->redis->get($this->prefix . $key);
        if ($raw === null) {
            return null;
        }
        $data = @unserialize($raw, ['allowed_classes' => false]);
        return is_array($data) && count($data) === 2 ? [$data[0], null] : null;
    }

    protected function write(string $key, mixed $value, ?int $expires): bool
    {
        $ttl = $expires === null ? null : $expires - time();
        if ($ttl !== null && $ttl <= 0) {
            $this->redis->del($this->prefix . $key);
            return true;
        }
        $this->redis->set($this->prefix . $key, serialize([$value, null]), $ttl);
        return true;
    }

    public function delete(string $key): bool
    {
        $this->assertKey($key);
        $this->redis->del($this->prefix . $key);
        return true;
    }

    public function clear(): bool
    {
        $this->redis->deletePattern($this->prefix . '*');
        return true;
    }

    public function increment(string $key, int $ttl): int
    {
        $this->assertKey($key);
        return $this->redis->incrWithTtl($this->prefix . 'counter:' . $key, $ttl);
    }
}
