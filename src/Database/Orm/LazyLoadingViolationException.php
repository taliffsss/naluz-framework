<?php

declare(strict_types=1);

namespace Naluz\Database\Orm;

/** Thrown (when the guard is on) by an accidental lazy-load — the classic N+1 query bug. */
final class LazyLoadingViolationException extends \LogicException
{
    public function __construct(public readonly string $model, public readonly string $relation)
    {
        parent::__construct(sprintf(
            "Attempted to lazy load [%s] on model [%s]. Eager load it with ::with('%s') or ->load('%s').",
            $relation,
            $model,
            $relation,
            $relation
        ));
    }
}
