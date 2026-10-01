<?php

declare(strict_types=1);

namespace Naluz\Database\Orm;

use Naluz\Database\Factory;
use Naluz\Support\Str;

/**
 * `User::factory()` resolves `Database\Factories\UserFactory` (database/factories/UserFactory.php),
 * or override `newFactory()`.
 *
 * @mixin Model
 */
trait HasFactory
{
    public static function factory(?int $count = null, array $state = []): Factory
    {
        $factory = static::newFactory() ?? static::guessFactory();
        if ($state !== []) {
            $factory = $factory->state($state);
        }
        return $count === null ? $factory : $factory->count($count);
    }

    protected static function newFactory(): ?Factory
    {
        return null;
    }

    private static function guessFactory(): Factory
    {
        $class = 'Database\\Factories\\' . Str::classBasename(static::class) . 'Factory';
        if (!class_exists($class) || !is_subclass_of($class, Factory::class)) {
            throw new \LogicException("Factory [{$class}] not found. Create it with `php naluz make:factory " . Str::classBasename(static::class) . 'Factory`.');
        }
        return new $class();
    }
}
