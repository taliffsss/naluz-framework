<?php

declare(strict_types=1);

namespace Naluz\Database\Orm;

/**
 * Rows are flagged with `deleted_at` instead of removed. Add a `softDeletes()` column in the migration.
 *
 * @mixin Model
 */
trait SoftDeletes
{
    public function trashed(): bool
    {
        return $this->getAttribute('deleted_at') !== null;
    }

    public function restore(): bool
    {
        $this->setAttribute('deleted_at', null);
        return $this->save();
    }

    public function forceDelete(): bool
    {
        if (!$this->exists) {
            return false;
        }
        $this->newBaseQuery()->where($this->getKeyName(), $this->getKey())->delete();
        $this->exists = false;
        return true;
    }
}
