<?php

declare(strict_types=1);

namespace Naluz\NoSql;

use Naluz\NoSql\Engine\Updater;

/**
 * Adapter over a `MongoDB\Collection` (mongodb/mongodb + ext-mongodb). The collection object is used through its public
 * methods only, so this file does not need the library to be installed.
 *
 * Added protection: server-side JavaScript operators (`$where`, `$function`, `$accumulator`) are refused in every filter,
 * and documents / `$set` values are validated so `$`-prefixed or dotted field names can never be stored.
 */
final class MongoCollection implements DocumentCollection
{
    private const TYPE_MAP = ['root' => 'array', 'document' => 'array', 'array' => 'array'];
    private const FORBIDDEN = ['$where', '$function', '$accumulator'];

    public function __construct(private readonly object $collection)
    {
    }

    public function query(): Query
    {
        return new Query($this);
    }

    public function insertOne(array $document): string|int
    {
        Updater::assertStorable($document);
        return $this->fromBson($this->collection->insertOne($this->toBson($document))->getInsertedId());
    }

    public function insertMany(array $documents): array
    {
        foreach ($documents as $d) {
            Updater::assertStorable($d);
        }
        return array_map([$this, 'fromBson'], array_values($this->collection->insertMany(array_map([$this, 'toBson'], $documents))->getInsertedIds()));
    }

    public function find(array $filter = [], array $options = []): array
    {
        $opts = ['typeMap' => self::TYPE_MAP];
        foreach (['sort', 'projection'] as $k) {
            if (!empty($options[$k])) {
                $opts[$k] = $options[$k];
            }
        }
        foreach (['limit', 'skip'] as $k) {
            if (!empty($options[$k])) {
                $opts[$k] = (int) $options[$k];
            }
        }
        $cursor = $this->collection->find($this->filter($filter), $opts);
        return array_values(array_map([$this, 'fromBson'], is_array($cursor) ? $cursor : iterator_to_array($cursor, false)));
    }

    public function findOne(array $filter = [], array $options = []): ?array
    {
        return $this->find($filter, ['limit' => 1] + $options)[0] ?? null;
    }

    public function count(array $filter = []): int
    {
        return (int) $this->collection->countDocuments($this->filter($filter));
    }

    public function distinct(string $field, array $filter = []): array
    {
        return array_map([$this, 'fromBson'], (array) $this->collection->distinct(Updater::fieldName($field), $this->filter($filter)));
    }

    public function updateOne(array $filter, array $update, bool $upsert = false): UpdateResult
    {
        return $this->result($this->collection->updateOne($this->filter($filter), $this->update($update), ['upsert' => $upsert]));
    }

    public function updateMany(array $filter, array $update, bool $upsert = false): UpdateResult
    {
        return $this->result($this->collection->updateMany($this->filter($filter), $this->update($update), ['upsert' => $upsert]));
    }

    public function replaceOne(array $filter, array $replacement, bool $upsert = false): UpdateResult
    {
        Updater::assertStorable($replacement);
        return $this->result($this->collection->replaceOne($this->filter($filter), $this->toBson($replacement), ['upsert' => $upsert]));
    }

    public function deleteOne(array $filter): int
    {
        return (int) $this->collection->deleteOne($this->filter($filter))->getDeletedCount();
    }

    public function deleteMany(array $filter): int
    {
        return (int) $this->collection->deleteMany($this->filter($filter))->getDeletedCount();
    }

    public function createIndex(array $keys, array $options = []): void
    {
        foreach (array_keys($keys) as $k) {
            Updater::fieldName((string) $k);
        }
        $this->collection->createIndex($keys, ['unique' => (bool) ($options['unique'] ?? false)]);
    }

    public function drop(): void
    {
        $this->collection->drop();
    }

    // ---------------------------------------------------------------- translation

    private function result(object $r): UpdateResult
    {
        $id = $r->getUpsertedId();
        return new UpdateResult((int) $r->getMatchedCount(), (int) $r->getModifiedCount(), $id === null ? null : $this->fromBson($id));
    }

    /** @param array<string,mixed> $update */
    private function update(array $update): array
    {
        foreach ($update as $op => $fields) {
            if (!is_string($op) || $op === '' || $op[0] !== '$') {
                throw new \InvalidArgumentException('Updates must use operators such as $set; use replaceOne() to replace a document.');
            }
            if (in_array($op, self::FORBIDDEN, true) || !is_array($fields)) {
                throw new \InvalidArgumentException("Update operator [{$op}] is not allowed.");
            }
            if (in_array($op, ['$set', '$setOnInsert', '$push', '$addToSet', '$min', '$max'], true)) {
                foreach ($fields as $path => $value) {
                    Updater::fieldName((string) $path);
                    if (is_array($value)) {
                        Updater::assertStorable($op === '$push' || $op === '$addToSet' ? ($value['$each'] ?? [$value]) : [$value]);
                    }
                }
            } else {
                foreach (array_keys($fields) as $path) {
                    Updater::fieldName((string) $path);
                }
            }
        }
        return $update;
    }

    /** @param array<string,mixed> $filter */
    private function filter(array $filter): array
    {
        return $this->walk($filter);
    }

    private function walk(array $node, bool $idContext = false): array
    {
        $out = [];
        foreach ($node as $key => $value) {
            if (is_string($key) && in_array($key, self::FORBIDDEN, true)) {
                throw new \InvalidArgumentException("The {$key} operator (server-side JavaScript) is not allowed.");
            }
            $isId = $key === '_id' || ($idContext && is_string($key) && in_array($key, ['$eq', '$ne', '$in', '$nin', '$gt', '$gte', '$lt', '$lte'], true));
            $out[$key] = match (true) {
                is_array($value) => $this->walk($value, $isId),
                $isId => $this->toId($value),
                default => $value,
            };
        }
        return $out;
    }

    /** 24-hex strings are ObjectIds in MongoDB; keep other ids as they are. */
    private function toId(mixed $v): mixed
    {
        return is_string($v) && preg_match('/^[0-9a-f]{24}$/', $v) && class_exists('MongoDB\\BSON\\ObjectId') ? new \MongoDB\BSON\ObjectId($v) : $v;
    }

    private function toBson(array $doc): array
    {
        return isset($doc['_id']) ? ['_id' => $this->toId($doc['_id'])] + array_diff_key($doc, ['_id' => 1]) : $doc;
    }

    private function fromBson(mixed $v): mixed
    {
        if (is_array($v)) {
            return array_map([$this, 'fromBson'], $v);
        }
        if (is_object($v)) {
            return match (true) {
                method_exists($v, 'toDateTime') => $v->toDateTime()->format(DATE_ATOM),
                $v instanceof \Traversable => array_map([$this, 'fromBson'], iterator_to_array($v)),
                $v instanceof \Stringable || method_exists($v, '__toString') => (string) $v,
                default => (array) $v,
            };
        }
        return $v;
    }
}
