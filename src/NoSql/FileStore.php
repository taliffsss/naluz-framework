<?php

declare(strict_types=1);

namespace Naluz\NoSql;

/** JSON-file store: one `<collection>.json` per collection under a directory. */
final class FileStore implements DocumentStore
{
    public function __construct(private readonly string $directory)
    {
    }

    public function collection(string $name): DocumentCollection
    {
        return new FileCollection($this->directory . '/' . MemoryStore::name($name) . '.json');
    }

    public function collections(): array
    {
        $names = [];
        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            $names[] = basename($file, '.json');
        }
        sort($names);
        return $names;
    }

    public function dropCollection(string $name): void
    {
        $file = $this->directory . '/' . MemoryStore::name($name) . '.json';
        @unlink($file);
        @unlink($file . '.lock');
    }
}
