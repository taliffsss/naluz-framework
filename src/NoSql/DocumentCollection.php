<?php

declare(strict_types=1);

namespace Naluz\NoSql;

/**
 * A MongoDB-style collection. Filters use the familiar operator syntax:
 *
 *   ['age' => ['$gte' => 18], 'status' => 'active']
 *   ['$or' => [['role' => 'admin'], ['score' => ['$gt' => 90]]]]
 *
 * SECURITY: a filter array is a *query*, not data. Never put request input straight into one
 * (`['password' => $_POST['p']]` lets an attacker send `p[$ne]=` and match everything). Use the builder — `query()` —
 * which treats every value as a literal, or cast/validate input to scalars first.
 */
interface DocumentCollection
{
    /** @param array<string,mixed> $document @return string|int the document's `_id` (generated when missing) */
    public function insertOne(array $document): string|int;

    /** @param list<array<string,mixed>> $documents @return list<string|int> */
    public function insertMany(array $documents): array;

    /**
     * @param array<string,mixed> $filter
     * @param array{sort?:array<string,int>,limit?:int,skip?:int,projection?:array<string,int>} $options
     * @return list<array<string,mixed>>
     */
    public function find(array $filter = [], array $options = []): array;

    /** @return array<string,mixed>|null */
    public function findOne(array $filter = [], array $options = []): ?array;

    public function count(array $filter = []): int;

    /** @return list<mixed> distinct values of a field */
    public function distinct(string $field, array $filter = []): array;

    /** @param array<string,mixed> $update operators: $set $unset $inc $mul $min $max $push $pull $addToSet $rename $setOnInsert */
    public function updateOne(array $filter, array $update, bool $upsert = false): UpdateResult;

    public function updateMany(array $filter, array $update, bool $upsert = false): UpdateResult;

    /** Replace a whole document (keeps `_id`). */
    public function replaceOne(array $filter, array $replacement, bool $upsert = false): UpdateResult;

    public function deleteOne(array $filter): int;

    public function deleteMany(array $filter): int;

    /** @param array<string,int> $keys field => 1|-1 @param array{unique?:bool} $options */
    public function createIndex(array $keys, array $options = []): void;

    public function drop(): void;

    /** Fluent, injection-safe query builder. */
    public function query(): Query;
}
