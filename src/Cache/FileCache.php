<?php

declare(strict_types=1);

namespace Naluz\Cache;

/**
 * PSR-16 file cache. Keys are hashed into file names (no path traversal) and values are
 * unserialized with `allowed_classes => false`, so a tampered cache file cannot instantiate objects.
 */
final class FileCache extends ArrayCache
{
    public function __construct(private readonly string $directory)
    {
    }

    public function clear(): bool
    {
        foreach (glob($this->directory . '/*.cache') ?: [] as $file) {
            @unlink($file);
        }
        return true;
    }

    /** Delete expired entries (they are otherwise only removed when read). @return int files removed */
    public function prune(): int
    {
        $n = 0;
        foreach (glob($this->directory . '/*.cache') ?: [] as $file) {
            $raw = @file_get_contents($file);
            $entry = $raw === false ? null : $this->decode($raw);
            if ($entry === null || ($entry[1] !== null && $entry[1] <= time())) {
                $n += @unlink($file) ? 1 : 0;
            }
        }
        return $n;
    }

    public function delete(string $key): bool
    {
        $this->assertKey($key);
        @unlink($this->path($key));
        return true;
    }

    public function increment(string $key, int $ttl): int
    {
        $this->assertKey($key);
        $path = $this->path($key);
        $this->ensureDirectory();
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            return 1;
        }
        flock($handle, LOCK_EX);
        $entry = $this->decode((string) stream_get_contents($handle));
        $value = ($entry[0] ?? 0) + 1;
        $expires = $entry[1] ?? time() + $ttl;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, serialize([$value, $expires]));
        flock($handle, LOCK_UN);
        fclose($handle);
        return $value;
    }

    protected function read(string $key): ?array
    {
        $path = $this->path($key);
        $raw = is_file($path) ? @file_get_contents($path) : false;
        if ($raw === false) {
            return null;
        }
        $entry = $this->decode($raw);
        if ($entry === null || ($entry[1] !== null && $entry[1] <= time())) {
            @unlink($path);
            return null;
        }
        return $entry;
    }

    protected function write(string $key, mixed $value, ?int $expires): bool
    {
        $this->ensureDirectory();
        return file_put_contents($this->path($key), serialize([$value, $expires]), LOCK_EX) !== false;
    }

    /** @return array{0:mixed,1:?int}|null */
    private function decode(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }
        $data = @unserialize($raw, ['allowed_classes' => false]);
        return is_array($data) && count($data) === 2 ? array_values($data) : null;
    }

    private function path(string $key): string
    {
        return $this->directory . '/' . hash('sha256', $key) . '.cache';
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }
    }
}
