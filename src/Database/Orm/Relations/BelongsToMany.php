<?php

declare(strict_types=1);

namespace Naluz\Database\Orm\Relations;

use Naluz\Database\Orm\Builder;
use Naluz\Database\Orm\Model;
use Naluz\Support\Collection;

final class BelongsToMany extends Relation
{
    public function __construct(
        Builder $query,
        Model $parent,
        private string $pivotTable,
        private string $foreignPivotKey,
        private string $relatedPivotKey,
        private string $parentKey,
        private string $relatedKey,
    ) {
        parent::__construct($query, $parent);
        $this->query
            ->join($pivotTable, $query->getModel()->getTable() . '.' . $relatedKey, '=', $pivotTable . '.' . $relatedPivotKey)
            ->select($query->getModel()->getTable() . '.*', "{$pivotTable}.{$foreignPivotKey} as pivot_{$foreignPivotKey}");
    }

    public function addConstraints(): void
    {
        $this->query->where($this->pivotTable . '.' . $this->foreignPivotKey, '=', $this->parent->getAttribute($this->parentKey));
    }

    public function addEagerConstraints(array $models): void
    {
        $this->query->whereIn($this->pivotTable . '.' . $this->foreignPivotKey, $this->keys($models, $this->parentKey));
    }

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
        $dict = [];
        foreach ($results as $r) {
            $attr = "pivot_{$this->foreignPivotKey}";
            $dict[$r->getAttribute($attr)][] = $r;
            $r->removeAttribute($attr);
        }
        foreach ($models as $m) {
            $m->setRelation($relation, new Collection($dict[$m->getAttribute($this->parentKey)] ?? []));
        }
        return $models;
    }

    // ------------------------------------------------------------- pivot ops

    /** @param int|string|Model|list<int|string|Model> $ids */
    public function attach(int|string|Model|array $ids, array $pivotAttributes = []): void
    {
        $existing = $this->pivotIds();
        $rows = [];
        foreach ($this->normalize($ids) as $id) {
            if (!in_array($id, $existing, true)) {
                $rows[] = [
                    $this->foreignPivotKey => $this->parent->getAttribute($this->parentKey),
                    $this->relatedPivotKey => $id,
                ] + $pivotAttributes;
            }
        }
        if ($rows !== []) {
            $this->pivot()->insert($rows);
        }
    }

    /** @param int|string|Model|list<int|string|Model>|null $ids null = detach everything */
    public function detach(int|string|Model|array|null $ids = null): int
    {
        $q = $this->pivot()->where($this->foreignPivotKey, '=', $this->parent->getAttribute($this->parentKey));
        if ($ids !== null) {
            $q->whereIn($this->relatedPivotKey, $this->normalize($ids));
        }
        return $q->delete();
    }

    /**
     * Make the pivot table match exactly the given ids.
     *
     * @param list<int|string|Model> $ids
     * @return array{attached:list<int|string>,detached:list<int|string>}
     */
    public function sync(array $ids): array
    {
        $target = $this->normalize($ids);
        $current = $this->pivotIds();
        $detach = array_values(array_diff($current, $target));
        $attach = array_values(array_diff($target, $current));
        if ($detach !== []) {
            $this->detach($detach);
        }
        if ($attach !== []) {
            $this->attach($attach);
        }
        return ['attached' => $attach, 'detached' => $detach];
    }

    private function pivot(): \Naluz\Database\Query\Builder
    {
        return $this->parent->connection()->table($this->pivotTable);
    }

    /** @return list<int|string> */
    private function pivotIds(): array
    {
        return array_map(
            static fn ($v) => is_numeric($v) ? (int) $v : $v,
            $this->pivot()->where($this->foreignPivotKey, '=', $this->parent->getAttribute($this->parentKey))->pluck($this->relatedPivotKey)->all()
        );
    }

    /** @return list<int|string> */
    private function normalize(int|string|Model|array $ids): array
    {
        return array_map(
            fn ($id) => $id instanceof Model ? $id->getAttribute($this->relatedKey) : (is_numeric($id) ? (int) $id : $id),
            array_values(is_array($ids) ? $ids : [$ids])
        );
    }
}
