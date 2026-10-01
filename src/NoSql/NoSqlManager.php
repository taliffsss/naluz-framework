<?php

declare(strict_types=1);

namespace Naluz\NoSql;

use Naluz\Config\Repository;

/** Named NoSQL connections from `config/nosql.php`. */
final class NoSqlManager
{
    /** @var array<string,DocumentStore> */
    private array $stores = [];

    public function __construct(private readonly Repository $config, private readonly string $basePath)
    {
    }

    public function connection(?string $name = null): DocumentStore
    {
        $name ??= (string) $this->config->get('nosql.default', 'file');
        return $this->stores[$name] ??= $this->make($name);
    }

    public function collection(string $name, ?string $connection = null): DocumentCollection
    {
        return $this->connection($connection)->collection($name);
    }

    public function extend(string $name, DocumentStore $store): void
    {
        $this->stores[$name] = $store;
    }

    private function make(string $name): DocumentStore
    {
        $cfg = $this->config->get("nosql.connections.{$name}")
            ?? throw new \InvalidArgumentException("NoSQL connection [{$name}] is not configured.");
        return match ($cfg['driver'] ?? null) {
            'memory' => new MemoryStore(),
            'file' => new FileStore(str_starts_with((string) ($cfg['path'] ?? ''), '/') ? (string) $cfg['path'] : $this->basePath . '/' . ($cfg['path'] ?? 'storage/nosql')),
            'mongodb' => MongoStore::fromConfig($cfg),
            default => throw new \InvalidArgumentException("NoSQL connection [{$name}] has an unsupported driver."),
        };
    }
}
