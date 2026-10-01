<?php

declare(strict_types=1);

namespace Naluz\GraphQL\Type;

final class ListType extends Type
{
    /** @param Type|\Closure():Type $of */
    public function __construct(private readonly Type|\Closure $of)
    {
    }

    public function of(): Type
    {
        return Type::resolve($this->of);
    }

    public function name(): ?string
    {
        return null;
    }

    public function __toString(): string
    {
        return '[' . $this->of() . ']';
    }
}
