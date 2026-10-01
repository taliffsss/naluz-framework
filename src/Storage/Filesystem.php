<?php

declare(strict_types=1);

namespace Naluz\Storage;

interface Filesystem
{
    public function exists(string $path): bool;

    public function get(string $path): string;

    /** @param string|resource $contents */
    public function put(string $path, mixed $contents): void;

    public function delete(string $path): bool;

    public function copy(string $from, string $to): void;

    public function move(string $from, string $to): void;

    public function size(string $path): int;

    public function lastModified(string $path): int;

    public function mimeType(string $path): string;

    /** @return list<string> file paths directly inside $directory */
    public function files(string $directory = ''): array;

    /** @return list<string> */
    public function directories(string $directory = ''): array;

    public function makeDirectory(string $path): void;

    public function deleteDirectory(string $path): bool;

    /** Public URL for a stored file (only meaningful for web-accessible disks). */
    public function url(string $path): string;
}
