<?php

declare(strict_types=1);

namespace Naluz\NoSql;

/** A NoSQL database: a set of named collections of schemaless documents. */
interface DocumentStore
{
    public function collection(string $name): DocumentCollection;

    /** @return list<string> */
    public function collections(): array;

    public function dropCollection(string $name): void;
}
