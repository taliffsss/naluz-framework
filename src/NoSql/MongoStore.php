<?php

declare(strict_types=1);

namespace Naluz\NoSql;

/** MongoDB through the official `mongodb/mongodb` library (`composer require mongodb/mongodb`, needs ext-mongodb). */
final class MongoStore implements DocumentStore
{
    /** @param object $database a `MongoDB\Database` */
    public function __construct(private readonly object $database)
    {
    }

    /** @param array{uri?:string,database?:string,options?:array} $config */
    public static function fromConfig(array $config): self
    {
        if (!class_exists('MongoDB\\Client')) {
            throw new \RuntimeException('The mongodb driver needs the MongoDB PHP library: install ext-mongodb and run `composer require mongodb/mongodb`.');
        }
        $uri = (string) ($config['uri'] ?? 'mongodb://127.0.0.1:27017');
        $client = new \MongoDB\Client($uri, (array) ($config['options'] ?? []), ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);
        return new self($client->selectDatabase((string) ($config['database'] ?? 'naluz')));
    }

    public function collection(string $name): DocumentCollection
    {
        return new MongoCollection($this->database->selectCollection(MemoryStore::name($name)));
    }

    public function collections(): array
    {
        return array_values(array_map('strval', iterator_to_array($this->database->listCollectionNames(), false)));
    }

    public function dropCollection(string $name): void
    {
        $this->database->dropCollection(MemoryStore::name($name));
    }
}
