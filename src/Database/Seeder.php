<?php

declare(strict_types=1);

namespace Naluz\Database;

use Naluz\Container\Container;

abstract class Seeder
{
    public function __construct(protected readonly Container $container)
    {
    }

    abstract public function run(): void;

    /** Run other seeders. @param class-string<Seeder>|list<class-string<Seeder>> $classes */
    public function call(string|array $classes): void
    {
        foreach ((array) $classes as $class) {
            if (!is_subclass_of($class, self::class)) {
                throw new \InvalidArgumentException("[{$class}] is not a seeder.");
            }
            $this->container->make($class)->run();
        }
    }
}
