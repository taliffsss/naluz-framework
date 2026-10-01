<?php

declare(strict_types=1);

namespace Naluz\Database\Orm\Relations;

use Naluz\Database\Orm\Builder;
use Naluz\Database\Orm\Model;
use Naluz\Support\Collection;

final class BelongsTo extends Relation
{
    public function __construct(Builder $query, Model $parent, private string $foreignKey, private string $ownerKey)
    {
        parent::__construct($query, $parent);
    }

    public function addConstraints(): void
    {
        $this->query->where($this->qualified(), '=', $this->parent->getAttribute($this->foreignKey));
    }

    public function addEagerConstraints(array $models): void
    {
        $this->query->whereIn($this->qualified(), $this->keys($models, $this->foreignKey));
    }

    private function qualified(): string
    {
        return $this->query->getModel()->getTable() . '.' . $this->ownerKey;
    }

    public function getResults(): ?Model
    {
        return $this->parent->getAttribute($this->foreignKey) === null ? null : $this->query->first();
    }

    public function initRelation(array $models, string $relation): array
    {
        foreach ($models as $m) {
            $m->setRelation($relation, null);
        }
        return $models;
    }

    public function match(array $models, Collection $results, string $relation): array
    {
        $dict = [];
        foreach ($results as $r) {
            $dict[$r->getAttribute($this->ownerKey)] = $r;
        }
        foreach ($models as $m) {
            $fk = $m->getAttribute($this->foreignKey);
            // nullable foreign keys: never use null as an array key (deprecated in PHP 8.5)
            $m->setRelation($relation, $fk === null ? null : ($dict[$fk] ?? null));
        }
        return $models;
    }

    /** Point the child at a parent model (does not save). */
    public function associate(Model $owner): Model
    {
        $this->parent->setAttribute($this->foreignKey, $owner->getAttribute($this->ownerKey));
        return $this->parent;
    }
}
