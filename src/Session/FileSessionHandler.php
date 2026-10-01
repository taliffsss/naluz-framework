<?php

declare(strict_types=1);

namespace Naluz\Session;

/** File-backed handler. IDs are validated against a strict pattern, so they can never traverse paths. */
final class FileSessionHandler implements \SessionHandlerInterface
{
    public function __construct(private readonly string $directory, private readonly int $lifetime = 7200)
    {
    }

    public function open(string $path, string $name): bool
    {
        return is_dir($this->directory) || @mkdir($this->directory, 0770, true);
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $file = $this->path($id);
        if (!is_file($file) || filemtime($file) < time() - $this->lifetime) {
            return '';
        }
        return (string) file_get_contents($file);
    }

    public function write(string $id, string $data): bool
    {
        $this->open('', '');
        return file_put_contents($this->path($id), $data, LOCK_EX) !== false;
    }

    public function destroy(string $id): bool
    {
        @unlink($this->path($id));
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $n = 0;
        foreach (glob($this->directory . '/sess_*') ?: [] as $file) {
            if (filemtime($file) < time() - $max_lifetime && @unlink($file)) {
                $n++;
            }
        }
        return $n;
    }

    private function path(string $id): string
    {
        if (!Store::isValidId($id)) {
            throw new \InvalidArgumentException('Invalid session id.');
        }
        return $this->directory . '/sess_' . $id;
    }
}
