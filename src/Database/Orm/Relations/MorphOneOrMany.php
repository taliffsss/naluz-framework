<?php

declare(strict_types=1);

namespace Naluz\Database\Orm\Relations;

use Naluz\Database\Orm\Builder;
use Naluz\Database\Orm\Model;
use Naluz\Support\Collection;

/** Shared logic for morphOne / morphMany: `{name}_type` + `{name}_id` columns on the related table. */
abstract class MorphOneOrMany extends Relation
{
    public function __construct(
        Builder $query,
        Model $parent,
        protected string $morphType,
        protected string $morphId,
        protected string $localKey,
    ) {
        parent::__construct($query, $parent);
    }

    private function table(): string
    {
        return $this->query->getModel()->getTable();
    }

    public function addConstraints(): void
    {
        $this->query
            ->where($this->table() . '.' . $this->morphType, '=', $this->parent->getMorphClass())
            ->where($this->table() . '.' . $this->morphId, '=', $this->parent->getAttribute($this->localKey));
    }

    public function addEagerConstraints(array $models): void
    {
        $this->query
            ->where($this->table() . '.' . $this->morphType, '=', $this->parent->getMorphClass())
            ->whereIn($this->table() . '.' . $this->morphId, $this->keys($models, $this->localKey));
    }

    public function create(array $attributes): Model
    {
        $model = $this->query->getModel()->newInstance();
        $model->forceFill($attributes + [
            $this->morphType => $this->parent->getMorphClass(),
            $this->morphId => $this->parent->getAttribute($this->localKey),
        ]);
        $model->save();
        return $model;
    }

    /** @return array<int|string,list<Model>> */
    protected function dictionary(Collection $results): array
    {
        $dict = [];
        foreach ($results as $r) {
            $dict[$r->getAttribute($this->morphId)][] = $r;
        }
        return $dict;
    }
}
