<?php

declare(strict_types=1);

namespace Naluz\GraphQL\Type;

abstract class Type
{
    /** The type's name, or null for list / non-null wrappers. */
    abstract public function name(): ?string;

    abstract public function __toString(): string;

    final public static function assertName(string $name, string $what): void
    {
        if (!preg_match('/^[_A-Za-z][_0-9A-Za-z]*$/', $name) || str_starts_with($name, '__')) {
            throw new \InvalidArgumentException("Invalid GraphQL {$what} name [{$name}]: use letters, digits and underscores, and do not start with \"__\".");
        }
    }

    /** @param Type|\Closure():Type $type */
    final public static function resolve(Type|\Closure $type): Type
    {
        $type = $type instanceof \Closure ? $type() : $type;
        if (!$type instanceof Type) {
            throw new \InvalidArgumentException('A GraphQL type reference must be a Type or a closure returning one.');
        }
        return $type;
    }

    /** Strips every list / non-null wrapper. */
    final public function named(): Type
    {
        $t = $this;
        while ($t instanceof ListType || $t instanceof NonNullType) {
            $t = $t->of();
        }
        return $t;
    }
}
