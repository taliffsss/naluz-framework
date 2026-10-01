<?php

declare(strict_types=1);

namespace Naluz\Config;

use Naluz\Support\Arr;

final class Repository
{
    public function __construct(private array $items = [])
    {
    }

    /** Load every `*.php` file in a directory; the file name becomes the top-level key. */
    public static function fromDirectory(string $dir): self
    {
        $items = [];
        foreach (glob(rtrim($dir, '/\\') . '/*.php') ?: [] as $file) {
            $items[basename($file, '.php')] = require $file;
        }
        return new self($items);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->items, $key, $default);
    }

    public function has(string $key): bool
    {
        return Arr::has($this->items, $key);
    }

    public function set(string $key, mixed $value): void
    {
        Arr::set($this->items, $key, $value);
    }

    public function all(): array
    {
        return $this->items;
    }
}
