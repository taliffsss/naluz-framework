<?php

declare(strict_types=1);

namespace Naluz\NoSql;

/** In-process store: nothing is persisted. For tests, caches and prototyping. */
final class MemoryStore implements DocumentStore
{
    /** @var array<string,MemoryCollection> */
    private array $collections = [];

    public function collection(string $name): DocumentCollection
    {
        return $this->collections[self::name($name)] ??= new MemoryCollection();
    }

    public function collections(): array
    {
        return array_keys($this->collections);
    }

    public function dropCollection(string $name): void
    {
        unset($this->collections[self::name($name)]);
    }

    public static function name(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,63}$/', $name) || str_contains($name, '..')) {
            throw new \InvalidArgumentException("Invalid collection name [{$name}].");
        }
        return $name;
    }
}
