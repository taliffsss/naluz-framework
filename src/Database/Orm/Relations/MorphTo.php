<?php

declare(strict_types=1);

namespace Naluz\Database\Orm\Relations;

use Naluz\Database\Orm\Builder;
use Naluz\Database\Orm\Model;
use Naluz\Support\Collection;

/**
 * The inverse side of a polymorphic relation: `$comment->commentable` may be a Post or a Video.
 *
 * Security: the `_type` column is data. It is resolved through the morph map when one is registered and, either way,
 * must name a subclass of Model — a tampered column can never instantiate an arbitrary class.
 */
final class MorphTo extends Relation
{
    /** @var list<Model> */
    private array $eagerModels = [];

    public function __construct(Builder $query, Model $parent, private string $morphType, private string $morphId)
    {
        parent::__construct($query, $parent);
    }

    public function addConstraints(): void
    {
    }

    public function addEagerConstraints(array $models): void
    {
        $this->eagerModels = $models;
    }

    public function getResults(): ?Model
    {
        $type = $this->parent->getAttribute($this->morphType);
        $id = $this->parent->getAttribute($this->morphId);
        if ($type === null || $id === null) {
            return null;
        }
        $related = Model::resolveMorphClass((string) $type);
        $instance = new $related();
        return $instance->newQuery()->where($instance->getQualifiedKeyName(), '=', $id)->first();
    }

    /** One query per distinct type. */
    public function getEager(): Collection
    {
        $byType = [];
        foreach ($this->eagerModels as $m) {
            $type = $m->getAttribute($this->morphType);
            $id = $m->getAttribute($this->morphId);
            if ($type !== null && $id !== null) {
                $byType[$type][$id] = $id;
            }
        }
        $all = [];
        foreach ($byType as $type => $ids) {
            $class = Model::resolveMorphClass((string) $type);
            $instance = new $class();
            foreach ($instance->newQuery()->whereIn($instance->getQualifiedKeyName(), array_values($ids))->get() as $found) {
                $found->setRawMorphType((string) $type);
                $all[] = $found;
            }
        }
        return new Collection($all);
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
            $dict[$r->morphTypeKey()][$r->getKey()] = $r;
        }
        foreach ($models as $m) {
            $type = $m->getAttribute($this->morphType);
            $id = $m->getAttribute($this->morphId);
            // orphans (NULL type/id) have no owner; never use null as an array key (deprecated in PHP 8.5)
            $m->setRelation($relation, $type === null || $id === null ? null : ($dict[$type][$id] ?? null));
        }
        return $models;
    }
}
