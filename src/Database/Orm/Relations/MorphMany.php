<?php

declare(strict_types=1);

namespace Naluz\Database\Orm\Relations;

use Naluz\Support\Collection;

final class MorphMany extends MorphOneOrMany
{
    public function getResults(): Collection
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
        $dict = $this->dictionary($results);
        foreach ($models as $m) {
            $m->setRelation($relation, new Collection($dict[$m->getAttribute($this->localKey)] ?? []));
        }
        return $models;
    }
}
