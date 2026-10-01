<?php

declare(strict_types=1);

namespace Naluz\NoSql;

use Naluz\NoSql\Engine\Matcher;
use Naluz\NoSql\Engine\Path;
use Naluz\NoSql\Engine\Updater;

/**
 * Full document-collection implementation on PHP arrays. The `memory` driver uses it as is; the `file` driver adds
 * persistence by overriding transaction().
 */
class MemoryCollection implements DocumentCollection
{
    /** @var array{docs:array<string,array<string,mixed>>,indexes:list<array{keys:list<string>,unique:bool}>} */
    protected array $state = ['docs' => [], 'indexes' => []];

    /**
     * Run `$fn` with exclusive access to the collection state. Subclasses load/save around it.
     *
     * @template T
     * @param \Closure(array):T $fn receives the state by reference
     * @return T
     */
    protected function transaction(\Closure $fn, bool $write): mixed
    {
        return $fn($this->state);
    }

    protected static function idKey(string|int $id): string
    {
        return (is_int($id) ? 'i:' : 's:') . $id;
    }

    public function query(): Query
    {
        return new Query($this);
    }

    // ---------------------------------------------------------------- insert

    public function insertOne(array $document): string|int
    {
        return $this->insertMany([$document])[0];
    }

    public function insertMany(array $documents): array
    {
        return $this->transaction(function (array &$state) use ($documents) {
            $ids = [];
            $staged = $state;
            foreach ($documents as $doc) {
                if (!is_array($doc)) {
                    throw new \InvalidArgumentException('Documents must be arrays.');
                }
                Updater::assertStorable($doc);
                $id = $doc['_id'] ?? bin2hex(random_bytes(12));
                if (!is_string($id) && !is_int($id)) {
                    throw new \InvalidArgumentException('_id must be a string or an integer.');
                }
                $doc = ['_id' => $id] + array_diff_key($doc, ['_id' => 1]);
                $this->assertUnique($staged, $doc, null);
                $staged['docs'][self::idKey($id)] = $doc;
                $ids[] = $id;
            }
            $state = $staged; // all-or-nothing
            return $ids;
        }, true);
    }

    // ---------------------------------------------------------------- read

    public function find(array $filter = [], array $options = []): array
    {
        return $this->transaction(function (array &$state) use ($filter, $options) {
            $docs = array_values(array_filter($state['docs'], static fn (array $d) => Matcher::matches($d, $filter)));
            if (!empty($options['sort'])) {
                $docs = Matcher::sort($docs, $options['sort']);
            }
            $docs = array_slice($docs, max(0, (int) ($options['skip'] ?? 0)), isset($options['limit']) && $options['limit'] > 0 ? (int) $options['limit'] : null);
            return array_map(static fn (array $d) => Updater::project($d, $options['projection'] ?? []), $docs);
        }, false);
    }

    public function findOne(array $filter = [], array $options = []): ?array
    {
        return $this->find($filter, ['limit' => 1] + $options)[0] ?? null;
    }

    public function count(array $filter = []): int
    {
        return $this->transaction(
            static fn (array &$state) => count(array_filter($state['docs'], static fn (array $d) => Matcher::matches($d, $filter))),
            false
        );
    }

    public function distinct(string $field, array $filter = []): array
    {
        $seen = [];
        foreach ($this->find($filter) as $doc) {
            foreach (Path::values($doc, $field) as $v) {
                foreach (is_array($v) && array_is_list($v) ? $v : [$v] as $item) {
                    $seen[json_encode($item)] = $item;
                }
            }
        }
        return array_values($seen);
    }

    // ---------------------------------------------------------------- update

    public function updateOne(array $filter, array $update, bool $upsert = false): UpdateResult
    {
        return $this->modify($filter, $update, $upsert, true, false);
    }

    public function updateMany(array $filter, array $update, bool $upsert = false): UpdateResult
    {
        return $this->modify($filter, $update, $upsert, false, false);
    }

    public function replaceOne(array $filter, array $replacement, bool $upsert = false): UpdateResult
    {
        return $this->modify($filter, $replacement, $upsert, true, true);
    }

    private function modify(array $filter, array $update, bool $upsert, bool $onlyOne, bool $replace): UpdateResult
    {
        return $this->transaction(function (array &$state) use ($filter, $update, $upsert, $onlyOne, $replace) {
            $apply = function (array $doc, bool $inserting) use ($update, $replace): array {
                if ($replace) {
                    Updater::assertStorable($update);
                    return ['_id' => $doc['_id']] + array_diff_key($update, ['_id' => 1]);
                }
                return Updater::apply($doc, $update, $inserting);
            };

            $matched = $modified = 0;
            $staged = $state;
            foreach ($state['docs'] as $key => $doc) {
                if (!Matcher::matches($doc, $filter)) {
                    continue;
                }
                $matched++;
                $new = $apply($doc, false);
                if (($new['_id'] ?? null) !== $doc['_id']) {
                    throw new \InvalidArgumentException('The _id field is immutable.');
                }
                Updater::assertStorable($new);
                if (json_encode($new) !== json_encode($doc)) {
                    $this->assertUnique($staged, $new, $key);
                    $staged['docs'][$key] = $new;
                    $modified++;
                }
                if ($onlyOne) {
                    break;
                }
            }

            $upsertedId = null;
            if ($matched === 0 && $upsert) {
                $seed = ['_id' => bin2hex(random_bytes(12))];
                foreach ($filter as $field => $cond) { // equality conditions seed the new document
                    if (is_string($field) && $field[0] !== '$' && !(is_array($cond) && $cond !== [] && !array_is_list($cond) && str_starts_with((string) array_key_first($cond), '$'))) {
                        Path::set($seed, $field, $cond);
                    } elseif (is_array($cond) && array_key_exists('$eq', $cond) && is_string($field) && $field[0] !== '$') {
                        Path::set($seed, $field, $cond['$eq']);
                    }
                }
                $new = $apply($seed, true);
                Updater::assertStorable($new);
                $this->assertUnique($staged, $new, null);
                $staged['docs'][self::idKey($new['_id'])] = $new;
                $upsertedId = $new['_id'];
            }
            $state = $staged;
            return new UpdateResult($matched, $modified, $upsertedId);
        }, true);
    }

    // ---------------------------------------------------------------- delete

    public function deleteOne(array $filter): int
    {
        return $this->remove($filter, true);
    }

    public function deleteMany(array $filter): int
    {
        return $this->remove($filter, false);
    }

    private function remove(array $filter, bool $onlyOne): int
    {
        return $this->transaction(function (array &$state) use ($filter, $onlyOne) {
            $n = 0;
            foreach ($state['docs'] as $key => $doc) {
                if (Matcher::matches($doc, $filter)) {
                    unset($state['docs'][$key]);
                    $n++;
                    if ($onlyOne) {
                        break;
                    }
                }
            }
            return $n;
        }, true);
    }

    // ---------------------------------------------------------------- indexes

    public function createIndex(array $keys, array $options = []): void
    {
        if ($keys === []) {
            throw new \InvalidArgumentException('An index needs at least one key.');
        }
        $fields = array_map(static fn ($k) => Updater::fieldName((string) $k), array_keys($keys));
        $this->transaction(function (array &$state) use ($fields, $options) {
            foreach ($state['indexes'] as $existing) {
                if ($existing['keys'] === $fields) {
                    return;
                }
            }
            $index = ['keys' => $fields, 'unique' => (bool) ($options['unique'] ?? false)];
            if ($index['unique']) { // existing data must already satisfy it
                $probe = ['docs' => [], 'indexes' => [$index]];
                foreach ($state['docs'] as $key => $doc) {
                    $this->assertUnique($probe, $doc, null);
                    $probe['docs'][$key] = $doc;
                }
            }
            $state['indexes'][] = $index;
        }, true);
    }

    public function drop(): void
    {
        $this->transaction(function (array &$state): void {
            $state = ['docs' => [], 'indexes' => []];
        }, true);
    }

    /** @param array{docs:array,indexes:list<array>} $state */
    private function assertUnique(array $state, array $doc, ?string $ignoreKey): void
    {
        $own = self::idKey($doc['_id']);
        if (isset($state['docs'][$own]) && $own !== $ignoreKey) {
            throw new DuplicateKeyException('Duplicate key: _id ' . json_encode($doc['_id']));
        }
        foreach ($state['indexes'] as $index) {
            if (!$index['unique']) {
                continue;
            }
            $tuple = json_encode(array_map(static fn ($k) => Path::first($doc, $k), $index['keys']));
            foreach ($state['docs'] as $key => $other) {
                if ($key !== $ignoreKey && $key !== $own && json_encode(array_map(static fn ($k) => Path::first($other, $k), $index['keys'])) === $tuple) {
                    throw new DuplicateKeyException('Duplicate key on unique index (' . implode(', ', $index['keys']) . ')');
                }
            }
        }
    }
}
