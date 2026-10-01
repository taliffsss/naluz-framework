<?php

declare(strict_types=1);

namespace Naluz\Database\Orm\Relations;

use Naluz\Database\Orm\Builder;
use Naluz\Database\Orm\Model;
use Naluz\Support\Collection;

/** @mixin Builder */
abstract class Relation
{
    private static bool $constraints = true;

    public function __construct(protected Builder $query, protected Model $parent)
    {
        if (self::$constraints) {
            $this->addConstraints();
        }
    }

    /** Build a relation without the parent-specific WHERE (used for eager loading). */
    public static function noConstraints(\Closure $callback): mixed
    {
        $previous = self::$constraints;
        self::$constraints = false;
        try {
            return $callback();
        } finally {
            self::$constraints = $previous;
        }
    }

    abstract public function addConstraints(): void;

    /** @param list<Model> $models */
    abstract public function addEagerConstraints(array $models): void;

    /** @param list<Model> $models @return list<Model> */
    abstract public function initRelation(array $models, string $relation): array;

    /** @param list<Model> $models @return list<Model> */
    abstract public function match(array $models, Collection $results, string $relation): array;

    abstract public function getResults(): mixed;

    public function getQuery(): Builder
    {
        return $this->query;
    }

    /** Run the (eager) query. Relations needing something smarter, like MorphTo, override this. */
    public function getEager(): Collection
    {
        return $this->query->get();
    }

    public function get(): Collection
    {
        return $this->query->get();
    }

    /**
     * @param list<Model> $models
     * @return list<int|string>
     */
    protected function keys(array $models, string $key): array
    {
        $keys = [];
        foreach ($models as $model) {
            $v = $model->getAttribute($key);
            if ($v !== null) {
                $keys[$v] = $v;
            }
        }
        return array_values($keys);
    }

    public function __call(string $method, array $args): mixed
    {
        $result = $this->query->{$method}(...$args);
        return $result === $this->query ? $this : $result;
    }
}
