<?php

declare(strict_types=1);

namespace Naluz\GraphQL;

use Naluz\GraphQL\Type\EnumType;
use Naluz\GraphQL\Type\InputObjectType;
use Naluz\GraphQL\Type\ListType;
use Naluz\GraphQL\Type\NonNullType;
use Naluz\GraphQL\Type\ObjectType;
use Naluz\GraphQL\Type\ScalarType;
use Naluz\GraphQL\Type\Type;

/** The standard `__schema` / `__type` introspection types, so GraphiQL, Apollo and codegen tools work. */
final class Introspection
{
    /** @return array<string,Type> */
    public static function types(Schema $schema): array
    {
        static $cache = null; // one set per schema instance
        $cache ??= new \WeakMap();
        if (isset($cache[$schema])) {
            return $cache[$schema];
        }

        $type = null;
        $typeRef = function () use (&$type) {
            return $type;
        };
        $nn = static fn (Type|\Closure $t) => Types::nonNull($t);
        $str = Types::string();
        $bool = Types::boolean();

        $kind = new EnumType('__TypeKind', ['SCALAR' => 'SCALAR', 'OBJECT' => 'OBJECT', 'INTERFACE' => 'INTERFACE', 'UNION' => 'UNION', 'ENUM' => 'ENUM', 'INPUT_OBJECT' => 'INPUT_OBJECT', 'LIST' => 'LIST', 'NON_NULL' => 'NON_NULL'], null, true);
        $location = new EnumType('__DirectiveLocation', ['FIELD' => 'FIELD', 'FRAGMENT_SPREAD' => 'FRAGMENT_SPREAD', 'INLINE_FRAGMENT' => 'INLINE_FRAGMENT'], null, true);

        $inputValue = new ObjectType('__InputValue', fn () => [
            'name' => ['type' => $nn($str), 'resolve' => fn ($a) => $a['name']],
            'description' => ['type' => $str, 'resolve' => fn ($a) => $a['description']],
            'type' => ['type' => $nn($typeRef), 'resolve' => fn ($a) => $a['type']],
            'defaultValue' => ['type' => $str, 'resolve' => fn ($a) => $a['hasDefault'] ? self::printValue($a['default'], $a['type']) : null],
        ], null, true);
        $field = new ObjectType('__Field', fn () => [
            'name' => ['type' => $nn($str), 'resolve' => fn ($f) => $f['name']],
            'description' => ['type' => $str, 'resolve' => fn ($f) => $f['description']],
            'args' => ['type' => $nn(Types::listOf($nn($inputValue))), 'resolve' => fn ($f) => array_values($f['args'])],
            'type' => ['type' => $nn($typeRef), 'resolve' => fn ($f) => $f['type']],
            'isDeprecated' => ['type' => $nn($bool), 'resolve' => fn ($f) => $f['deprecated'] !== null],
            'deprecationReason' => ['type' => $str, 'resolve' => fn ($f) => $f['deprecated']],
        ], null, true);
        $enumValue = new ObjectType('__EnumValue', fn () => [
            'name' => ['type' => $nn($str), 'resolve' => fn ($v) => $v['name']],
            'description' => ['type' => $str, 'resolve' => fn ($v) => $v['description']],
            'isDeprecated' => ['type' => $nn($bool), 'resolve' => fn ($v) => $v['deprecated'] !== null],
            'deprecationReason' => ['type' => $str, 'resolve' => fn ($v) => $v['deprecated']],
        ], null, true);

        $type = new ObjectType('__Type', fn () => [
            'kind' => ['type' => $nn($kind), 'resolve' => fn (Type $t) => match (true) {
                $t instanceof ScalarType => 'SCALAR',
                $t instanceof ObjectType => 'OBJECT',
                $t instanceof EnumType => 'ENUM',
                $t instanceof InputObjectType => 'INPUT_OBJECT',
                $t instanceof ListType => 'LIST',
                default => 'NON_NULL',
            }],
            'name' => ['type' => $str, 'resolve' => fn (Type $t) => $t->name()],
            'description' => ['type' => $str, 'resolve' => fn (Type $t) => $t->description ?? null],
            'fields' => [
                'type' => Types::listOf($nn($field)),
                'args' => ['includeDeprecated' => ['type' => $bool, 'default' => false]],
                'resolve' => fn (Type $t, array $args) => $t instanceof ObjectType
                    ? array_values(array_filter($t->fields(), fn ($f) => ($args['includeDeprecated'] ?? false) || $f['deprecated'] === null))
                    : null,
            ],
            'interfaces' => ['type' => Types::listOf($nn($typeRef)), 'resolve' => fn (Type $t) => $t instanceof ObjectType ? [] : null],
            'possibleTypes' => ['type' => Types::listOf($nn($typeRef)), 'resolve' => fn () => null],
            'enumValues' => [
                'type' => Types::listOf($nn($enumValue)),
                'args' => ['includeDeprecated' => ['type' => $bool, 'default' => false]],
                'resolve' => fn (Type $t, array $args) => $t instanceof EnumType
                    ? array_values(array_filter($t->values(), fn ($v) => ($args['includeDeprecated'] ?? false) || $v['deprecated'] === null))
                    : null,
            ],
            'inputFields' => ['type' => Types::listOf($nn($inputValue)), 'resolve' => fn (Type $t) => $t instanceof InputObjectType ? array_values($t->fields()) : null],
            'ofType' => ['type' => $typeRef, 'resolve' => fn (Type $t) => $t instanceof ListType || $t instanceof NonNullType ? $t->of() : null],
        ], null, true);

        $directive = new ObjectType('__Directive', fn () => [
            'name' => ['type' => $nn($str), 'resolve' => fn ($d) => $d['name']],
            'description' => ['type' => $str, 'resolve' => fn ($d) => $d['description']],
            'locations' => ['type' => $nn(Types::listOf($nn($location))), 'resolve' => fn ($d) => $d['locations']],
            'args' => ['type' => $nn(Types::listOf($nn($inputValue))), 'resolve' => fn ($d) => $d['args']],
        ], null, true);

        $directives = ['skip' => 'Skips this field or fragment when the argument is true.', 'include' => 'Includes this field or fragment only when the argument is true.'];
        $schemaType = new ObjectType('__Schema', fn () => [
            'description' => ['type' => $str, 'resolve' => fn () => null],
            'types' => ['type' => $nn(Types::listOf($nn($typeRef))), 'resolve' => fn () => array_values($schema->typeMap())],
            'queryType' => ['type' => $nn($typeRef), 'resolve' => fn () => $schema->query],
            'mutationType' => ['type' => $typeRef, 'resolve' => fn () => $schema->mutation],
            'subscriptionType' => ['type' => $typeRef, 'resolve' => fn () => null],
            'directives' => ['type' => $nn(Types::listOf($nn($directive))), 'resolve' => function () use ($directives, $bool, $nn) {
                $out = [];
                foreach ($directives as $name => $description) {
                    $out[] = [
                        'name' => $name,
                        'description' => $description,
                        'locations' => ['FIELD', 'FRAGMENT_SPREAD', 'INLINE_FRAGMENT'],
                        'args' => [['name' => 'if', 'description' => null, 'type' => $nn($bool), 'default' => null, 'hasDefault' => false]],
                    ];
                }
                return $out;
            }],
        ], null, true);

        return $cache[$schema] = [
            '__Schema' => $schemaType, '__Type' => $type, '__Field' => $field, '__InputValue' => $inputValue,
            '__EnumValue' => $enumValue, '__Directive' => $directive, '__TypeKind' => $kind, '__DirectiveLocation' => $location,
        ];
    }

    /** The two entry points added to the query root: `__schema` and `__type(name:)`. @return array<string,array<string,mixed>> */
    public static function rootFields(Schema $schema): array
    {
        $all = self::types($schema);
        return [
            '__schema' => [
                'name' => '__schema', 'type' => Types::nonNull($all['__Schema']), 'args' => [], 'description' => null, 'deprecated' => null,
                'resolve' => fn () => $schema,
            ],
            '__type' => [
                'name' => '__type', 'type' => $all['__Type'], 'description' => null, 'deprecated' => null,
                'args' => ['name' => ['name' => 'name', 'type' => Types::nonNull(Types::string()), 'default' => null, 'hasDefault' => false, 'description' => null]],
                'resolve' => fn ($s, array $args) => $schema->type($args['name']),
            ],
        ];
    }

    private static function printValue(mixed $value, Type $type): string
    {
        if ($value === null) {
            return 'null';
        }
        $inner = $type instanceof NonNullType ? $type->of() : $type;
        if ($inner instanceof ListType) {
            return '[' . implode(', ', array_map(fn ($v) => self::printValue($v, $inner->of()), (array) $value)) . ']';
        }
        if ($inner instanceof EnumType) {
            try {
                return $inner->serialize($value);
            } catch (\InvalidArgumentException) {
                return 'null';
            }
        }
        if ($inner instanceof InputObjectType) {
            $parts = [];
            foreach ($inner->fields() as $name => $def) {
                if (is_array($value) && array_key_exists($name, $value)) {
                    $parts[] = $name . ': ' . self::printValue($value[$name], $def['type']);
                }
            }
            return '{' . implode(', ', $parts) . '}';
        }
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
