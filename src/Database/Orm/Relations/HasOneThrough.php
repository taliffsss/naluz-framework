<?php

declare(strict_types=1);

namespace Naluz\Database\Orm\Relations;

use Naluz\Database\Orm\Model;
use Naluz\Support\Collection;

final class HasOneThrough extends HasManyThrough
{
    public function getResults(): Collection|Model|null
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

    protected function shape(array $items): Collection|Model|null
    {
        return $items[0] ?? null;
    }
}
