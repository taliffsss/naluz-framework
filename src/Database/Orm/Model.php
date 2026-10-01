<?php

declare(strict_types=1);

namespace Naluz\Database\Orm;

use Naluz\Database\Connection;
use Naluz\Database\DatabaseManager;
use Naluz\Database\Orm\Relations\BelongsTo;
use Naluz\Database\Orm\Relations\BelongsToMany;
use Naluz\Database\Orm\Relations\HasMany;
use Naluz\Database\Orm\Relations\HasManyThrough;
use Naluz\Database\Orm\Relations\HasOne;
use Naluz\Database\Orm\Relations\HasOneThrough;
use Naluz\Database\Orm\Relations\MorphMany;
use Naluz\Database\Orm\Relations\MorphOne;
use Naluz\Database\Orm\Relations\MorphTo;
use Naluz\Database\Orm\Relations\Relation;
use Naluz\Security\Encrypter;
use Naluz\Security\Hasher;
use Naluz\Support\Collection;
use Naluz\Support\Str;
use Psr\Container\ContainerInterface;

/**
 * Active-record base model.
 *
 * Security defaults: mass assignment is OFF until you declare `$fillable`; `$hidden` attributes
 * never serialise; every query is parameterised.
 *
 * @method static Builder query()
 * @method static Builder where(string|\Closure|array $column, mixed $operator = null, mixed $value = null)
 * @method static Builder with(string|array ...$relations)
 * @method static static|Collection|null find(int|string|array $id)
 * @method static static findOrFail(int|string $id)
 * @method static static|null first()
 * @method static static firstOrCreate(array $attributes, array $values = [])
 * @method static static updateOrCreate(array $attributes, array $values = [])
 * @method static int count(string $column = '*')
 */
abstract class Model implements \JsonSerializable, \ArrayAccess
{
    protected ?string $table = null;
    protected string $primaryKey = 'id';
    protected ?string $connection = null;
    protected bool $incrementing = true;
    protected bool $timestamps = true;

    /** @var list<string> attributes allowed in mass assignment (create/fill/update) */
    protected array $fillable = [];
    /** @var list<string> */
    protected array $hidden = [];
    /** @var list<string> accessor-backed attributes added to toArray() */
    protected array $appends = [];
    /** @var array<string,string> attribute => int|float|bool|string|array|json|datetime|date|encrypted|hashed|EnumClass */
    protected array $casts = [];
    /** Opt this model out of MODEL_CACHING with `false` (e.g. rows that must always be read live). */
    protected bool $cache = true;
    /** Per-model cache TTL in seconds (null = MODEL_CACHE_TTL). */
    protected ?int $cacheTtl = null;

    private array $attributes = [];
    private array $original = [];
    private array $relations = [];
    public bool $exists = false;
    public bool $wasRecentlyCreated = false;
    private ?string $morphTypeOverride = null;

    private static bool $preventLazyLoading = false;
    private static ?\Naluz\Database\ModelCache $modelCache = null;
    /** @var array<string,class-string<Model>> */
    private static array $morphMap = [];

    private static ?ContainerInterface $container = null;
    /** @var array<class-string,array<string,list<\Closure>>> */
    private static array $listeners = [];
    private const EVENTS = ['saving', 'saved', 'creating', 'created', 'updating', 'updated', 'deleting', 'deleted'];

    final public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    public static function setContainer(?ContainerInterface $container): void
    {
        self::$container = $container;
    }

    /**
     * When on, touching a relationship that was not eager loaded throws instead of silently running a query per row.
     * Enabled automatically in debug / testing (`app.prevent_lazy_loading`). Records created in this request are exempt.
     */
    public static function preventLazyLoading(bool $value = true): void
    {
        self::$preventLazyLoading = $value;
    }

    /**
     * Name polymorphic types with short aliases instead of class names: `['post' => Post::class]`.
     * Strongly recommended: class names in the database are a refactoring hazard and leak your structure.
     *
     * @param array<string,class-string<Model>> $map
     */
    public static function morphMap(array $map, bool $merge = true): void
    {
        self::$morphMap = $merge ? $map + self::$morphMap : $map;
    }

    /** @return class-string<Model> */
    public static function resolveMorphClass(string $type): string
    {
        $class = self::$morphMap[$type] ?? (self::$morphMap === [] ? $type : null);
        if ($class === null || !is_subclass_of($class, self::class)) {
            throw new \InvalidArgumentException('Unknown or invalid polymorphic type.');
        }
        return $class;
    }

    public function getMorphClass(): string
    {
        $alias = array_search(static::class, self::$morphMap, true);
        return $alias === false ? static::class : (string) $alias;
    }

    /** @internal */
    public function morphTypeKey(): string
    {
        return $this->morphTypeOverride ?? $this->getMorphClass();
    }

    /** @internal remember which `_type` value located this model (for MorphTo matching) */
    public function setRawMorphType(string $type): void
    {
        $this->morphTypeOverride = $type;
    }

    /** @internal wired by ModelCacheServiceProvider when MODEL_CACHING=true */
    public static function setModelCache(?\Naluz\Database\ModelCache $cache): void
    {
        self::$modelCache = $cache;
    }

    /** The active query cache for this model, or null when caching is off or the model opted out. */
    public function modelCache(): ?\Naluz\Database\ModelCache
    {
        return $this->cache ? self::$modelCache : null;
    }

    public function cacheTtl(): ?int
    {
        return $this->cacheTtl;
    }

    /** Run a block with model caching switched off (reads go to the database; writes still invalidate). */
    public static function runWithoutCache(\Closure $callback): mixed
    {
        return self::$modelCache === null ? $callback() : self::$modelCache->runWithout($callback);
    }

    /** Drop every cached query that reads this model's table. */
    public static function flushCache(): void
    {
        $model = new static();
        self::$modelCache?->flushTable($model->connection()->name(), $model->getTable());
    }

    /** Forget every registered model event listener (useful in tests). */
    public static function flushEventListeners(): void
    {
        self::$listeners = [];
    }

    // ------------------------------------------------------------------ static API

    public static function query(): Builder
    {
        return (new static())->newQuery();
    }

    /** @return Collection<int,static> */
    public static function all(): Collection
    {
        return static::query()->get();
    }

    public static function create(array $attributes): static
    {
        return static::query()->create($attributes);
    }

    /** @param int|string|list<int|string> $ids */
    public static function destroy(int|string|array $ids): int
    {
        $count = 0;
        foreach (static::query()->find((array) $ids) as $model) {
            $count += $model->delete() ? 1 : 0;
        }
        return $count;
    }

    public static function __callStatic(string $method, array $args): mixed
    {
        if (in_array($method, self::EVENTS, true)) {
            self::$listeners[static::class][$method][] = $args[0];
            return null;
        }
        return static::query()->{$method}(...$args);
    }

    public function __call(string $method, array $args): mixed
    {
        return $this->newQuery()->{$method}(...$args);
    }

    public static function usesSoftDeletes(): bool
    {
        return in_array(SoftDeletes::class, class_uses(static::class) ?: [], true)
            || array_reduce(class_parents(static::class) ?: [], static fn ($c, $p) => $c || in_array(SoftDeletes::class, class_uses($p) ?: [], true), false);
    }

    // ------------------------------------------------------------------ metadata

    public function getTable(): string
    {
        return $this->table ?? Str::snake(Str::plural(Str::classBasename(static::class)));
    }

    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    public function getQualifiedKeyName(): string
    {
        return $this->getTable() . '.' . $this->primaryKey;
    }

    public function getKey(): mixed
    {
        return $this->getAttribute($this->primaryKey);
    }

    public function connection(): Connection
    {
        $container = self::$container ?? throw new \LogicException('No container set. Call Model::setContainer() while booting the application.');
        return $container->get(DatabaseManager::class)->connection($this->connection);
    }

    public function newQuery(): Builder
    {
        return new Builder($this->connection()->query(), $this);
    }

    public function newBaseQuery(): \Naluz\Database\Query\Builder
    {
        return $this->connection()->table($this->getTable());
    }

    public function newInstance(array $attributes = []): static
    {
        return new static($attributes);
    }

    public function newFromBuilder(array $row): static
    {
        $model = new static();
        $model->attributes = $row;
        $model->original = $row;
        $model->exists = true;
        return $model;
    }

    // ------------------------------------------------------------------ attributes

    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable((string) $key)) {
                $this->setAttribute((string) $key, $value);
            }
        }
        return $this;
    }

    /** Bypass mass-assignment protection. Never pass raw request input here. */
    public function forceFill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute((string) $key, $value);
        }
        return $this;
    }

    private function isFillable(string $key): bool
    {
        return in_array($key, $this->fillable, true);
    }

    public function getAttribute(string $key): mixed
    {
        $accessor = 'get' . Str::studly($key) . 'Attribute';
        if (method_exists($this, $accessor)) {
            return $this->{$accessor}($this->attributes[$key] ?? null);
        }
        if (array_key_exists($key, $this->attributes)) {
            return $this->castGet($key, $this->attributes[$key]);
        }
        return $this->relations[$key] ?? null;
    }

    public function setAttribute(string $key, mixed $value): static
    {
        $mutator = 'set' . Str::studly($key) . 'Attribute';
        if (method_exists($this, $mutator)) {
            $this->{$mutator}($value);
            return $this;
        }
        $this->attributes[$key] = $this->castSet($key, $value);
        return $this;
    }

    public function removeAttribute(string $key): void
    {
        unset($this->attributes[$key], $this->original[$key]);
    }

    /** Raw (uncast) value; used by mutators to store processed values. */
    public function setRawAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getRawAttributes(): array
    {
        return $this->attributes;
    }

    private function castGet(string $key, mixed $value): mixed
    {
        $cast = $this->casts[$key] ?? null;
        if ($value === null || $cast === null) {
            return $value;
        }
        return match ($cast) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'bool', 'boolean' => (bool) $value,
            'string' => (string) $value,
            'array', 'json' => is_array($value) ? $value : json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR),
            'datetime', 'date' => $value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable((string) $value),
            'encrypted' => $this->service(Encrypter::class)->decryptString((string) $value),
            'hashed' => $value,
            default => enum_exists($cast) ? $cast::from($value) : $value,
        };
    }

    private function castSet(string $key, mixed $value): mixed
    {
        $cast = $this->casts[$key] ?? null;
        if ($value === null || $cast === null) {
            return $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value;
        }
        return match ($cast) {
            'array', 'json' => is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'datetime', 'date' => $value instanceof \DateTimeInterface ? $value->format($cast === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s') : $value,
            'bool', 'boolean' => (int) (bool) $value,
            'encrypted' => $this->service(Encrypter::class)->encryptString((string) $value),
            'hashed' => $this->service(Hasher::class)->needsRehash((string) $value) || !str_starts_with((string) $value, '$')
                ? $this->service(Hasher::class)->make((string) $value) : $value,
            default => is_subclass_of($cast, \BackedEnum::class) && $value instanceof \BackedEnum ? $value->value : $value,
        };
    }

    private function service(string $id): object
    {
        return (self::$container ?? throw new \LogicException('No container set.'))->get($id);
    }

    // ------------------------------------------------------------------ dirty tracking

    public function isDirty(?string $key = null): bool
    {
        $dirty = $this->getDirty();
        return $key === null ? $dirty !== [] : array_key_exists($key, $dirty);
    }

    public function getDirty(): array
    {
        $dirty = [];
        foreach ($this->attributes as $k => $v) {
            if (!array_key_exists($k, $this->original) || !self::same($v, $this->original[$k])) {
                $dirty[$k] = $v;
            }
        }
        return $dirty;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if ($a === null || $b === null) {
            return false;
        }
        return is_numeric($a) && is_numeric($b) ? (string) $a === (string) $b : (is_scalar($a) && is_scalar($b) && (string) $a === (string) $b);
    }

    public function syncOriginal(): static
    {
        $this->original = $this->attributes;
        return $this;
    }

    // ------------------------------------------------------------------ persistence

    public function save(): bool
    {
        if ($this->fire('saving') === false) {
            return false;
        }
        $now = date('Y-m-d H:i:s');

        if ($this->exists) {
            if (!$this->isDirty()) {
                return true;
            }
            if ($this->fire('updating') === false) {
                return false;
            }
            if ($this->timestamps && !$this->isDirty('updated_at')) {
                $this->attributes['updated_at'] = $now;
            }
            $this->newBaseQuery()->where($this->primaryKey, '=', $this->original[$this->primaryKey] ?? $this->getKey())->update($this->getDirty());
            $this->fire('updated');
        } else {
            if ($this->fire('creating') === false) {
                return false;
            }
            if ($this->timestamps) {
                $this->attributes['created_at'] ??= $now;
                $this->attributes['updated_at'] ??= $now;
            }
            $query = $this->newBaseQuery();
            if ($this->incrementing && !isset($this->attributes[$this->primaryKey])) {
                $this->attributes[$this->primaryKey] = $query->insertGetId($this->attributes, $this->primaryKey);
            } else {
                $query->insert($this->attributes);
            }
            $this->exists = true;
            $this->wasRecentlyCreated = true;
            $this->fire('created');
        }
        $this->syncOriginal();
        $this->fire('saved');
        return true;
    }

    public function update(array $attributes): bool
    {
        return $this->exists && $this->fill($attributes)->save();
    }

    public function delete(): bool
    {
        if (!$this->exists || $this->fire('deleting') === false) {
            return false;
        }
        if (static::usesSoftDeletes()) {
            $this->setAttribute('deleted_at', date('Y-m-d H:i:s'));
            $this->save();
        } else {
            $this->newBaseQuery()->where($this->primaryKey, '=', $this->getKey())->delete();
            $this->exists = false;
        }
        $this->fire('deleted');
        return true;
    }

    public function fresh(): ?static
    {
        return $this->exists ? static::query()->withoutCache()->withTrashed()->find($this->getKey()) : null;
    }

    public function refresh(): static
    {
        $fresh = $this->fresh() ?? throw new ModelNotFoundException(static::class, (string) $this->getKey());
        $this->attributes = $fresh->attributes;
        $this->original = $fresh->original;
        $this->relations = [];
        return $this;
    }

    private function fire(string $event): bool|null
    {
        foreach (self::$listeners[static::class][$event] ?? [] as $listener) {
            if ($listener($this) === false) {
                return false;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ relationships

    public function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        $instance = new $related();
        return new HasOne($instance->newQuery(), $this, $foreignKey ?? $this->foreignKeyName(), $localKey ?? $this->primaryKey);
    }

    public function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        $instance = new $related();
        return new HasMany($instance->newQuery(), $this, $foreignKey ?? $this->foreignKeyName(), $localKey ?? $this->primaryKey);
    }

    public function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): BelongsTo
    {
        $instance = new $related();
        return new BelongsTo($instance->newQuery(), $this, $foreignKey ?? $instance->foreignKeyName(), $ownerKey ?? $instance->primaryKey);
    }

    public function belongsToMany(string $related, ?string $table = null, ?string $foreignPivotKey = null, ?string $relatedPivotKey = null): BelongsToMany
    {
        $instance = new $related();
        $names = [Str::snake(Str::classBasename(static::class)), Str::snake(Str::classBasename($related))];
        sort($names);
        $table ??= implode('_', $names);
        return new BelongsToMany(
            $instance->newQuery(),
            $this,
            $table,
            $foreignPivotKey ?? $this->foreignKeyName(),
            $relatedPivotKey ?? $instance->foreignKeyName(),
            $this->primaryKey,
            $instance->primaryKey
        );
    }

    public function morphOne(string $related, string $name, ?string $type = null, ?string $id = null, ?string $localKey = null): MorphOne
    {
        return new MorphOne((new $related())->newQuery(), $this, $type ?? $name . '_type', $id ?? $name . '_id', $localKey ?? $this->primaryKey);
    }

    public function morphMany(string $related, string $name, ?string $type = null, ?string $id = null, ?string $localKey = null): MorphMany
    {
        return new MorphMany((new $related())->newQuery(), $this, $type ?? $name . '_type', $id ?? $name . '_id', $localKey ?? $this->primaryKey);
    }

    /** `$comment->commentable` — the owner may be any model type. */
    public function morphTo(string $name, ?string $type = null, ?string $id = null): MorphTo
    {
        return new MorphTo($this->newQuery(), $this, $type ?? $name . '_type', $id ?? $name . '_id');
    }

    public function hasManyThrough(string $related, string $through, ?string $firstKey = null, ?string $secondKey = null, ?string $localKey = null, ?string $secondLocalKey = null): HasManyThrough
    {
        $throughModel = new $through();
        return new HasManyThrough(
            (new $related())->newQuery(),
            $this,
            $throughModel,
            $firstKey ?? $this->foreignKeyName(),
            $secondKey ?? $throughModel->foreignKeyName(),
            $localKey ?? $this->primaryKey,
            $secondLocalKey ?? $throughModel->primaryKey
        );
    }

    public function hasOneThrough(string $related, string $through, ?string $firstKey = null, ?string $secondKey = null, ?string $localKey = null, ?string $secondLocalKey = null): HasOneThrough
    {
        $throughModel = new $through();
        return new HasOneThrough(
            (new $related())->newQuery(),
            $this,
            $throughModel,
            $firstKey ?? $this->foreignKeyName(),
            $secondKey ?? $throughModel->foreignKeyName(),
            $localKey ?? $this->primaryKey,
            $secondLocalKey ?? $throughModel->primaryKey
        );
    }

    protected function foreignKeyName(): string
    {
        return Str::snake(Str::classBasename(static::class)) . '_' . $this->primaryKey;
    }

    public function setRelation(string $name, mixed $value): static
    {
        $this->relations[$name] = $value;
        return $this;
    }

    public function relationLoaded(string $name): bool
    {
        return array_key_exists($name, $this->relations);
    }

    /** Lazy-load relations after the fact: `$user->load('posts')`. */
    public function load(string ...$relations): static
    {
        $models = static::query()->with(...$relations)->whereIn($this->primaryKey, [$this->getKey()])->get();
        foreach ($relations as $name) {
            $head = explode('.', $name, 2)[0];
            $this->relations[$head] = $models->first()?->relations[$head] ?? null;
        }
        return $this;
    }

    // ------------------------------------------------------------------ magic & serialisation

    public function __get(string $key): mixed
    {
        if (
            array_key_exists($key, $this->attributes) || array_key_exists($key, $this->relations)
            || method_exists($this, 'get' . Str::studly($key) . 'Attribute')
        ) {
            return $this->getAttribute($key);
        }
        if (method_exists($this, $key) && !method_exists(self::class, $key)) {
            $relation = $this->{$key}();
            if ($relation instanceof Relation) {
                if (self::$preventLazyLoading && $this->exists && !$this->wasRecentlyCreated) {
                    throw new LazyLoadingViolationException(static::class, $key);
                }
                return $this->relations[$key] = $relation->getResults();
            }
        }
        return null;
    }

    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
    }

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]) || isset($this->relations[$key]);
    }

    public function __unset(string $key): void
    {
        unset($this->attributes[$key], $this->relations[$key]);
    }

    public function toArray(): array
    {
        $out = [];
        foreach (array_keys($this->attributes) as $key) {
            if (!in_array($key, $this->hidden, true)) {
                $value = $this->getAttribute($key);
                $out[$key] = $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM)
                    : ($value instanceof \BackedEnum ? $value->value : $value);
            }
        }
        foreach ($this->appends as $key) {
            $out[$key] = $this->getAttribute($key);
        }
        foreach ($this->relations as $name => $relation) {
            if (in_array($name, $this->hidden, true)) {
                continue;
            }
            $out[$name] = $relation instanceof self || $relation instanceof Collection ? $relation->toArray() : $relation;
        }
        return $out;
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | $flags);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->__isset((string) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->getAttribute((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->setAttribute((string) $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->__unset((string) $offset);
    }
}
