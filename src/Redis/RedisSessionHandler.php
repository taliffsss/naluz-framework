<?php

declare(strict_types=1);

namespace Naluz\Redis;

use Naluz\Session\Store;

final class RedisSessionHandler implements \SessionHandlerInterface
{
    public function __construct(private readonly Client $redis, private readonly int $lifetime = 7200, private readonly string $prefix = 'naluz:session:')
    {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        return Store::isValidId($id) ? (string) $this->redis->get($this->prefix . $id) : '';
    }

    public function write(string $id, string $data): bool
    {
        if (!Store::isValidId($id)) {
            return false;
        }
        $this->redis->set($this->prefix . $id, $data, $this->lifetime);
        return true;
    }

    public function destroy(string $id): bool
    {
        if (Store::isValidId($id)) {
            $this->redis->del($this->prefix . $id);
        }
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return 0; // Redis expires keys itself
    }
}
