<?php

declare(strict_types=1);

namespace Naluz\Database\Orm\Relations;

use Naluz\Database\Orm\Model;
use Naluz\Support\Collection;

final class HasOne extends HasOneOrMany
{
    public function getResults(): ?Model
    {
        return $this->query->first();
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
        $dict = $this->dictionary($results);
        foreach ($models as $m) {
            $m->setRelation($relation, ($dict[$m->getAttribute($this->localKey)] ?? [null])[0]);
        }
        return $models;
    }
}
