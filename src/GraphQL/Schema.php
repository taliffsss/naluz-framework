<?php

declare(strict_types=1);

namespace Naluz\GraphQL;

use Naluz\GraphQL\Type\EnumType;
use Naluz\GraphQL\Type\InputObjectType;
use Naluz\GraphQL\Type\ObjectType;
use Naluz\GraphQL\Type\Type;

/** The root of a GraphQL API: a query type and optionally a mutation type. Built in PHP, no SDL required. */
final class Schema
{
    /** @var array<string,Type>|null */
    private ?array $types = null;

    /** @param list<Type> $types extra types to expose that are not reachable from the roots */
    public function __construct(
        public readonly ObjectType $query,
        public readonly ?ObjectType $mutation = null,
        private readonly array $extraTypes = []
    ) {
    }

    /** @return array<string,Type> every named type, including built-in scalars and the introspection types */
    public function typeMap(): array
    {
        if ($this->types === null) {
            $map = [];
            $roots = array_filter([$this->query, $this->mutation, ...$this->extraTypes, ...Types::builtinScalars()]);
            foreach ($roots as $root) {
                $this->collect($root, $map);
            }
            foreach (Introspection::types($this) as $t) {
                $this->collect($t, $map);
            }
            ksort($map);
            $this->types = $map;
        }
        return $this->types;
    }

    public function type(string $name): ?Type
    {
        return $this->typeMap()[$name] ?? null;
    }

    /** @param array<string,Type> $map */
    private function collect(Type $type, array &$map): void
    {
        $type = $type->named();
        $name = (string) $type->name();
        if (isset($map[$name])) {
            if ($map[$name] !== $type) {
                throw new \InvalidArgumentException("Two different GraphQL types are both named [{$name}]. Type names must be unique; reuse one instance.");
            }
            return;
        }
        $map[$name] = $type;
        if ($type instanceof ObjectType) {
            foreach ($type->fields() as $field) {
                $this->collect($field['type'], $map);
                foreach ($field['args'] as $arg) {
                    $this->collect($arg['type'], $map);
                }
            }
        } elseif ($type instanceof InputObjectType) {
            foreach ($type->fields() as $field) {
                $this->collect($field['type'], $map);
            }
        } elseif (!$type instanceof EnumType && !$type instanceof \Naluz\GraphQL\Type\ScalarType) {
            throw new \InvalidArgumentException("Unsupported GraphQL type [{$name}].");
        }
    }
}
