<?php

declare(strict_types=1);

namespace Naluz\Database\Orm\Relations;

use Naluz\Database\Orm\Builder;
use Naluz\Database\Orm\Model;
use Naluz\Support\Collection;

abstract class HasOneOrMany extends Relation
{
    public function __construct(Builder $query, Model $parent, protected string $foreignKey, protected string $localKey)
    {
        parent::__construct($query, $parent);
    }

    public function addConstraints(): void
    {
        $this->query->where($this->qualified(), '=', $this->parent->getAttribute($this->localKey));
    }

    public function addEagerConstraints(array $models): void
    {
        $this->query->whereIn($this->qualified(), $this->keys($models, $this->localKey));
    }

    private function qualified(): string
    {
        return $this->query->getModel()->getTable() . '.' . $this->foreignKey;
    }

    /** Create a related record already pointing at the parent. */
    public function create(array $attributes): Model
    {
        $model = $this->query->getModel()->newInstance();
        $model->forceFill($attributes + [$this->foreignKey => $this->parent->getAttribute($this->localKey)]);
        $model->save();
        return $model;
    }

    protected function dictionary(Collection $results): array
    {
        $dict = [];
        foreach ($results as $result) {
            $dict[$result->getAttribute($this->foreignKey)][] = $result;
        }
        return $dict;
    }
}
