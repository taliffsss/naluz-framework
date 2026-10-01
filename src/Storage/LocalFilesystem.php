<?php

declare(strict_types=1);

namespace Naluz\Storage;

/**
 * Disk rooted at a directory. Every path is untrusted: NUL bytes, `..` segments, absolute paths, backslashes and
 * drive letters are rejected, and existing paths are re-checked with realpath() so symlinks cannot lead outside the root.
 */
final class LocalFilesystem implements Filesystem
{
    private readonly string $root;

    public function __construct(string $root, private readonly string $urlBase = '')
    {
        if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
            throw new StorageException('Cannot create storage root.');
        }
        $this->root = rtrim((string) realpath($root), '/\\');
    }

    /** @return string absolute path inside the root */
    private function resolve(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || preg_match('#^([A-Za-z]:|/|~)#', $path)) {
            if ($path !== '') { // '' means the root itself (for listings)
                throw new StorageException('Illegal storage path.');
            }
        }
        $clean = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                throw new StorageException('Illegal storage path.');
            }
            $clean[] = $seg;
        }
        $full = $this->root . ($clean ? '/' . implode('/', $clean) : '');
        $this->assertInside($full);
        return $full;
    }

    /** Resolve symlinks of the nearest existing ancestor and make sure it is still inside the root. */
    private function assertInside(string $full): void
    {
        $probe = $full;
        while (!file_exists($probe) && !is_link($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                break;
            }
            $probe = $parent;
        }
        $real = realpath($probe);
        if ($real === false || ($real !== $this->root && !str_starts_with($real, $this->root . DIRECTORY_SEPARATOR))) {
            throw new StorageException('Illegal storage path.');
        }
    }

    public function exists(string $path): bool
    {
        return file_exists($this->resolve($path));
    }

    public function get(string $path): string
    {
        $full = $this->resolve($path);
        $data = is_file($full) ? file_get_contents($full) : false;
        return $data === false ? throw new StorageException('File not found.') : $data;
    }

    public function put(string $path, mixed $contents): void
    {
        $full = $this->resolve($path);
        if (!is_dir(dirname($full)) && !@mkdir(dirname($full), 0775, true) && !is_dir(dirname($full))) {
            throw new StorageException('Cannot create directory.');
        }
        $this->assertInside($full);
        $tmp = $full . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $ok = false;
        if (is_resource($contents)) {
            $out = fopen($tmp, 'wb');
            $ok = $out !== false && stream_copy_to_stream($contents, $out) !== false && fclose($out);
        } else {
            $ok = file_put_contents($tmp, (string) $contents) !== false;
        }
        if (!$ok || !rename($tmp, $full)) {
            @unlink($tmp);
            throw new StorageException('Cannot write file.');
        }
    }

    public function delete(string $path): bool
    {
        $full = $this->resolve($path);
        return is_file($full) && unlink($full);
    }

    public function copy(string $from, string $to): void
    {
        $this->put($to, $this->get($from));
    }

    public function move(string $from, string $to): void
    {
        $this->copy($from, $to);
        $this->delete($from);
    }

    public function size(string $path): int
    {
        return (int) filesize($this->resolve($path));
    }

    public function lastModified(string $path): int
    {
        return (int) filemtime($this->resolve($path));
    }

    public function mimeType(string $path): string
    {
        return (new \finfo(FILEINFO_MIME_TYPE))->file($this->resolve($path)) ?: 'application/octet-stream';
    }

    public function files(string $directory = ''): array
    {
        return $this->list($directory, true);
    }

    public function directories(string $directory = ''): array
    {
        return $this->list($directory, false);
    }

    /** @return list<string> */
    private function list(string $directory, bool $files): array
    {
        $dir = $this->resolve($directory);
        $out = [];
        foreach (is_dir($dir) ? scandir($dir) ?: [] : [] as $name) {
            if ($name === '.' || $name === '..' || str_ends_with($name, '.tmp')) {
                continue;
            }
            if ((is_file($dir . '/' . $name)) === $files) {
                $out[] = ltrim(trim($directory, '/') . '/' . $name, '/');
            }
        }
        sort($out);
        return $out;
    }

    public function makeDirectory(string $path): void
    {
        $full = $this->resolve($path);
        if (!is_dir($full) && !@mkdir($full, 0775, true) && !is_dir($full)) {
            throw new StorageException('Cannot create directory.');
        }
    }

    public function deleteDirectory(string $path): bool
    {
        $full = $this->resolve($path);
        if ($full === $this->root || !is_dir($full)) {
            return false;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        return rmdir($full);
    }

    public function url(string $path): string
    {
        if ($this->urlBase === '') {
            throw new StorageException('This disk is not publicly accessible.');
        }
        $this->resolve($path);
        return rtrim($this->urlBase, '/') . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
    }
}
