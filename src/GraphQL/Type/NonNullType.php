<?php

declare(strict_types=1);

namespace Naluz\GraphQL\Type;

final class NonNullType extends Type
{
    /** @param Type|\Closure():Type $of */
    public function __construct(private readonly Type|\Closure $of)
    {
        if ($of instanceof NonNullType) {
            throw new \InvalidArgumentException('A non-null type cannot wrap another non-null type.');
        }
    }

    public function of(): Type
    {
        $t = Type::resolve($this->of);
        if ($t instanceof NonNullType) {
            throw new \InvalidArgumentException('A non-null type cannot wrap another non-null type.');
        }
        return $t;
    }

    public function name(): ?string
    {
        return null;
    }

    public function __toString(): string
    {
        return $this->of() . '!';
    }
}
