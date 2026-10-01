<?php

declare(strict_types=1);

namespace Naluz\Database\Orm;

use Naluz\Database\Orm\Relations\Relation;
use Naluz\Database\Paginator;
use Naluz\Database\Query\Builder as QueryBuilder;
use Naluz\Support\Collection;

/**
 * ORM query builder: wraps the base query builder, hydrates models, eager loads relations.
 * Any base-builder method (where, orderBy, join...) is forwarded and keeps the chain on this builder.
 *
 * @mixin QueryBuilder
 */
class Builder
{
    /** Methods that terminate the chain and return the base builder's raw result. */
    private const PASSTHRU = [
        'insert', 'insertGetId', 'upsert', 'exists', 'doesntExist', 'count', 'min', 'max', 'avg', 'sum',
        'toSql', 'getBindings', 'pluck', 'value', 'update', 'increment', 'decrement', 'truncate',
    ];

    /** @var array<string,\Closure|null> */
    private array $eagerLoad = [];
    /** Reads whose results are cached (when the model cache is on). pluck/value/aggregates return plain data. */
    private const CACHED_READS = ['exists', 'doesntExist', 'count', 'min', 'max', 'avg', 'sum', 'pluck', 'value'];

    private bool $noCache = false;
    private ?int $cacheSeconds = null;
    private bool $withTrashed = false;
    private bool $onlyTrashed = false;

    public function __construct(private QueryBuilder $query, private readonly Model $model)
    {
        $this->query->from($model->getTable());
    }

    public function getModel(): Model
    {
        return $this->model;
    }

    /** The underlying query builder with model-level scopes (soft deletes) applied. */
    public function toBase(): QueryBuilder
    {
        $query = clone $this->query;
        if ($this->model::usesSoftDeletes()) {
            $col = $this->model->getTable() . '.deleted_at';
            if ($this->onlyTrashed) {
                $query->whereNotNull($col);
            } elseif (!$this->withTrashed) {
                $query->whereNull($col);
            }
        }
        return $query;
    }

    /** Skip the model cache for this query (read live from the database, store nothing). */
    public function withoutCache(): static
    {
        $this->noCache = true;
        return $this;
    }

    /** Cache this query for `$seconds` instead of the default TTL. */
    public function cacheFor(int $seconds): static
    {
        $this->cacheSeconds = max(1, $seconds);
        return $this;
    }

    /**
     * Run `$load` through the model cache when that is safe: cache on, model not opted out, no raw SQL,
     * not inside a transaction (uncommitted data must never be cached), table not excluded.
     *
     * @template T
     * @param \Closure():T $load
     * @return T
     */
    private function remember(string $kind, QueryBuilder $query, \Closure $load, array $args = []): mixed
    {
        $cache = $this->noCache ? null : $this->model->modelCache();
        $connection = $query->connection;
        if ($cache === null || $connection->transactionLevel() > 0 || $query->hasRawSql() || !$cache->canCache($query->tables())) {
            return $load();
        }
        if ($cache->readsFromPrimary()) {
            $query->useWritePdo(); // $load runs on this same query object: misses are read from the primary
        }
        [$sql, $bindings] = $query->readPlan($kind, $args);
        return $cache->remember(
            $connection->name(),
            $query->tables(),
            $kind . '|' . $sql . '|' . json_encode([$bindings, $args === [] || $kind === 'rows' ? [] : $args], JSON_PARTIAL_OUTPUT_ON_ERROR),
            $this->cacheSeconds ?? $this->model->cacheTtl(),
            $load,
            ['kind' => $kind, 'sql' => $sql, 'bindings' => $bindings, 'args' => $args]
        );
    }

    public function withTrashed(): static
    {
        $this->withTrashed = true;
        return $this;
    }

    public function onlyTrashed(): static
    {
        $this->onlyTrashed = true;
        return $this;
    }

    /**
     * Eager load relations (solves N+1). Supports nesting ("posts.comments") and constraints:
     *   ->with('posts', 'author')  ->with(['posts' => fn ($q) => $q->latest()])
     */
    public function with(string|array ...$relations): static
    {
        foreach ($relations as $relation) {
            foreach ((array) $relation as $name => $constraint) {
                if (is_int($name)) {
                    $this->eagerLoad[$constraint] = null;
                } else {
                    $this->eagerLoad[$name] = $constraint;
                }
            }
        }
        return $this;
    }

    // ---------------------------------------------------------------- retrieval

    /** @return Collection<int,Model> */
    public function get(): Collection
    {
        $base = $this->toBase();
        $models = $this->hydrate($this->remember('rows', $base, fn () => $base->get()->all()));
        if ($models !== [] && $this->eagerLoad !== []) {
            $models = $this->eagerLoadRelations($models);
        }
        return new Collection($models);
    }

    public function first(): ?Model
    {
        return (clone $this)->limit(1)->get()->first();
    }

    public function firstOrFail(): Model
    {
        return $this->first() ?? throw new ModelNotFoundException($this->model::class);
    }

    /** @return Model|Collection<int,Model>|null */
    public function find(int|string|array $id): Model|Collection|null
    {
        if (is_array($id)) {
            return (clone $this)->whereIn($this->model->getQualifiedKeyName(), $id)->get();
        }
        return (clone $this)->where($this->model->getQualifiedKeyName(), '=', $id)->first();
    }

    public function findOrFail(int|string $id): Model
    {
        return $this->find($id) ?? throw new ModelNotFoundException($this->model::class, $id);
    }

    public function paginate(int $perPage = 15, int $page = 1): Paginator
    {
        $perPage = max(1, min($perPage, 1000));
        $page = max(1, $page);
        $total = $this->count();
        $items = $total > 0 ? (clone $this)->forPage($page, $perPage)->get() : new Collection();
        return new Paginator($items, $total, $perPage, $page);
    }

    public function chunk(int $size, \Closure $callback): bool
    {
        $page = 1;
        do {
            $models = (clone $this)->forPage($page, $size)->get();
            if ($models->isEmpty()) {
                break;
            }
            if ($callback($models, $page) === false) {
                return false;
            }
            $page++;
        } while ($models->count() === $size);
        return true;
    }

    /** @return \Generator<int,Model> */
    public function cursor(): \Generator
    {
        foreach ($this->toBase()->cursor() as $row) {
            yield $this->model->newFromBuilder($row);
        }
    }

    // ---------------------------------------------------------------- writing

    public function create(array $attributes): Model
    {
        $model = $this->model->newInstance($attributes);
        $model->save();
        return $model;
    }

    public function firstOrCreate(array $attributes, array $values = []): Model
    {
        return (clone $this)->where($attributes)->first() ?? $this->create($attributes + $values);
    }

    public function updateOrCreate(array $attributes, array $values = []): Model
    {
        $model = (clone $this)->where($attributes)->first();
        if ($model === null) {
            return $this->create($attributes + $values);
        }
        $model->fill($values)->save();
        return $model;
    }

    /** Bulk delete (soft by default for models using SoftDeletes). Does not fire model events. */
    public function delete(): int
    {
        if ($this->model::usesSoftDeletes()) {
            return $this->toBase()->update(['deleted_at' => date('Y-m-d H:i:s')]);
        }
        return $this->toBase()->delete();
    }

    public function forceDelete(): int
    {
        return $this->withTrashed()->toBase()->delete();
    }

    // ---------------------------------------------------------------- internals

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<Model>
     */
    public function hydrate(array $rows): array
    {
        return array_map(fn (array $row) => $this->model->newFromBuilder($row), $rows);
    }

    /**
     * @param list<Model> $models
     * @return list<Model>
     */
    private function eagerLoadRelations(array $models): array
    {
        $grouped = [];
        foreach ($this->eagerLoad as $name => $constraint) {
            [$head, $rest] = array_pad(explode('.', $name, 2), 2, null);
            $grouped[$head]['nested'] ??= [];
            if ($rest !== null) {
                $grouped[$head]['nested'][$rest] = $constraint;
            } else {
                $grouped[$head]['constraint'] = $constraint;
            }
        }

        foreach ($grouped as $name => $spec) {
            $relation = Relation::noConstraints(fn () => $models[0]->{$name}());
            if (!$relation instanceof Relation) {
                throw new \LogicException(sprintf('[%s::%s()] is not a relationship.', $this->model::class, $name));
            }
            $relation->addEagerConstraints($models);
            if (isset($spec['constraint'])) {
                ($spec['constraint'])($relation->getQuery());
            }
            if ($spec['nested'] !== []) {
                $relation->getQuery()->with($spec['nested']);
            }
            $models = $relation->match($relation->initRelation($models, $name), $relation->getEager(), $name);
        }
        return $models;
    }

    public function __call(string $method, array $args): mixed
    {
        $scope = 'scope' . ucfirst($method);
        if (method_exists($this->model, $scope)) {
            $this->model->{$scope}($this, ...$args);
            return $this;
        }
        if (in_array($method, self::CACHED_READS, true)) {
            $base = $this->toBase();
            if ($method === 'pluck') { // a Collection can't be stored; cache the plain array
                return new Collection($this->remember('pluck', $base, fn () => $base->pluck(...$args)->all(), $args));
            }
            return $this->remember($method, $base, fn () => $base->{$method}(...$args), $args);
        }
        if (in_array($method, self::PASSTHRU, true)) {
            return $this->toBase()->{$method}(...$args);
        }
        $result = $this->query->{$method}(...$args);
        return $result === $this->query ? $this : $result;
    }

    public function __clone()
    {
        $this->query = clone $this->query;
    }
}
