<?php

declare(strict_types=1);

namespace Naluz\Database\Orm\Attributes;

/**
 * Attach observers to a model declaratively:
 *
 *     #[ObservedBy(UserObserver::class)]
 *     class User extends Model { … }
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ObservedBy
{
    /** @var list<class-string> */
    public readonly array $observers;

    /** @param class-string|list<class-string> $observers */
    public function __construct(string|array $observers)
    {
        $this->observers = array_values((array) $observers);
    }
}
