<?php

declare(strict_types=1);

namespace Naluz\Database\Orm\Relations;

use Naluz\Database\Orm\Builder;
use Naluz\Database\Orm\Model;
use Naluz\Support\Collection;

/**
 * Country → (users) → posts. `$through` is the intermediate table.
 *
 *   related.secondKey = through.secondLocalKey   AND   through.firstKey = parent.localKey
 */
class HasManyThrough extends Relation
{
    private const ALIAS = 'naluz_through_key';

    public function __construct(
        Builder $query,
        Model $parent,
        protected Model $through,
        protected string $firstKey,
        protected string $secondKey,
        protected string $localKey,
        protected string $secondLocalKey,
    ) {
        parent::__construct($query, $parent);
        $related = $query->getModel()->getTable();
        $throughTable = $through->getTable();
        $this->query
            ->join($throughTable, $throughTable . '.' . $secondLocalKey, '=', $related . '.' . $secondKey)
            ->select($related . '.*', $throughTable . '.' . $firstKey . ' as ' . self::ALIAS);
    }

    public function addConstraints(): void
    {
        $this->query->where($this->through->getTable() . '.' . $this->firstKey, '=', $this->parent->getAttribute($this->localKey));
    }

    public function addEagerConstraints(array $models): void
    {
        $this->query->whereIn($this->through->getTable() . '.' . $this->firstKey, $this->keys($models, $this->localKey));
    }

    public function getResults(): Collection|Model|null
    {
        return $this->query->get();
    }

    public function initRelation(array $models, string $relation): array
    {
        foreach ($models as $m) {
            $m->setRelation($relation, new Collection());
        }
        return $models;
    }

    public function match(array $models, Collection $results, string $relation): array
    {
        $dict = [];
        foreach ($results as $r) {
            $dict[$r->getAttribute(self::ALIAS)][] = $r;
            $r->removeAttribute(self::ALIAS);
        }
        foreach ($models as $m) {
            $m->setRelation($relation, $this->shape($dict[$m->getAttribute($this->localKey)] ?? []));
        }
        return $models;
    }

    protected function shape(array $items): Collection|Model|null
    {
        return new Collection($items);
    }
}
