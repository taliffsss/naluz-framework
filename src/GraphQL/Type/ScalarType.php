<?php

declare(strict_types=1);

namespace Naluz\GraphQL\Type;

/**
 * A leaf type. Build custom scalars (DateTime, Email, …) with Types::scalar().
 * `parseValue` turns client JSON into a PHP value and `serialize` turns a PHP value into JSON; both throw
 * \InvalidArgumentException with a client-safe message when the value is not acceptable.
 */
final class ScalarType extends Type
{
    /**
     * @param \Closure(mixed):mixed $serialize
     * @param \Closure(mixed):mixed $parseValue
     * @param (\Closure(array):mixed)|null $parseLiteral receives the literal AST node; defaults to parseValue over the literal's value
     */
    public function __construct(
        private readonly string $name,
        private readonly \Closure $serialize,
        private readonly \Closure $parseValue,
        private readonly ?\Closure $parseLiteral = null,
        public readonly ?string $description = null
    ) {
        Type::assertName($name, 'scalar');
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function serialize(mixed $value): mixed
    {
        return ($this->serialize)($value);
    }

    public function parseValue(mixed $value): mixed
    {
        return ($this->parseValue)($value);
    }

    /** @param array<string,mixed> $ast */
    public function parseLiteral(array $ast): mixed
    {
        if ($this->parseLiteral !== null) {
            return ($this->parseLiteral)($ast);
        }
        return match ($ast['kind']) {
            'int', 'float', 'string', 'bool' => $this->parseValue($ast['value']),
            default => throw new \InvalidArgumentException("{$this->name} cannot represent this literal value."),
        };
    }
}
