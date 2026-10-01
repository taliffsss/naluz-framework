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

/**
 * Runs a validated operation. Field errors are collected next to partial data (`{"data": …, "errors": […]}`);
 * a null in a non-null position makes the nearest nullable parent null, as the GraphQL spec requires.
 * Exceptions that are not GraphQLError / HttpException are masked as "Internal server error".
 */
final class Executor
{
    private const UNSET = '__naluz_unset__';

    /** @var list<GraphQLError> */
    private array $errors = [];
    /** @var array<string,array<string,mixed>> */
    private array $fragments = [];
    /** @var array<string,mixed> */
    private array $variables = [];
    private mixed $context = null;

    /** @param (\Closure(\Throwable):void)|null $reporter called for unexpected (masked) exceptions, e.g. to log them */
    public function __construct(private readonly Schema $schema, private readonly bool $debug = false, private readonly ?\Closure $reporter = null)
    {
    }

    /**
     * @param array{operations:list<array<string,mixed>>,fragments:array<string,array<string,mixed>>} $document
     * @param array<string,mixed> $operation
     * @param array<string,mixed> $variables raw (JSON-decoded) variable values
     * @return array{data?:mixed,errors?:list<array<string,mixed>>}
     */
    public function execute(array $document, array $operation, array $variables, mixed $context = null): array
    {
        $this->errors = [];
        $this->fragments = $document['fragments'];
        $this->context = $context;

        try {
            $this->variables = $this->coerceVariables($operation['variables'], $variables);
        } catch (GraphQLError $e) {
            return ['errors' => [$e->toArray()]];
        }

        $root = $operation['type'] === 'mutation' ? $this->schema->mutation : $this->schema->query;
        \assert($root instanceof ObjectType);
        $groups = [];
        $this->collectFields($root, $operation['selections'], $groups, []);

        try {
            $data = $this->executeFields($root, $groups, null, []);
        } catch (NullBubble) {
            $data = null;
        }

        $result = ['data' => $data];
        if ($this->errors !== []) {
            $result['errors'] = array_map(static fn (GraphQLError $e) => $e->toArray(), $this->errors);
        }
        return $result;
    }

    // ------------------------------------------------------------------ execution

    /** @param array<string,list<array<string,mixed>>> $groups @param list<string|int> $path @return array<string,mixed> */
    private function executeFields(ObjectType $type, array $groups, mixed $source, array $path): array
    {
        $out = [];
        foreach ($groups as $key => $nodes) {
            $out[$key] = $this->executeField($type, $nodes, $source, [...$path, $key]);
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $nodes @param list<string|int> $path */
    private function executeField(ObjectType $parent, array $nodes, mixed $source, array $path): mixed
    {
        $name = $nodes[0]['name'];
        if ($name === '__typename') {
            return $parent->name();
        }
        $def = $parent->fields()[$name] ?? null;
        if ($def === null && $parent === $this->schema->query) {
            $def = Introspection::rootFields($this->schema)[$name] ?? null;
        }
        if ($def === null) {
            $this->errors[] = (new GraphQLError("Cannot query field \"{$name}\" on type \"{$parent}\"."))->atPath($path);
            return null;
        }

        return $this->guard($def['type'], $path, function () use ($def, $nodes, $source, $parent, $path, $name) {
            $args = $this->coerceArguments($def['args'], $nodes[0]['args']);
            $info = new ResolveInfo($name, $parent, $def['type'], $path, $nodes, $this->variables, $this->fragments, $this->schema);
            $resolver = $def['resolve'] ?? self::defaultResolver($name);
            $value = $resolver($source, $args, $this->context, $info);
            return $this->completeValue($def['type'], $nodes, $value, $path, $parent, $name);
        });
    }

    /**
     * Runs one nullable-or-not position: a NullBubble or an exception inside makes it null (and an error is recorded),
     * unless the position itself is non-null, in which case the problem moves up to the parent.
     *
     * @param list<string|int> $path
     */
    private function guard(Type $type, array $path, \Closure $run): mixed
    {
        try {
            return $run();
        } catch (NullBubble $b) {
            if ($type instanceof NonNullType) {
                throw $b;
            }
            return null;
        } catch (\Throwable $e) {
            $this->errors[] = $this->toError($e)->atPath($path);
            if ($type instanceof NonNullType) {
                throw new NullBubble();
            }
            return null;
        }
    }

    /** @param list<array<string,mixed>> $nodes @param list<string|int> $path */
    private function completeValue(Type $type, array $nodes, mixed $value, array $path, ObjectType $parent, string $field): mixed
    {
        if ($type instanceof NonNullType) {
            $result = $this->completeValue($type->of(), $nodes, $value, $path, $parent, $field);
            if ($result === null) {
                $this->errors[] = (new GraphQLError("Cannot return null for non-nullable field {$parent}.{$field}."))->atPath($path);
                throw new NullBubble();
            }
            return $result;
        }
        if ($value === null) {
            return null;
        }
        if ($type instanceof ListType) {
            if (!is_iterable($value)) {
                throw new \UnexpectedValueException("Expected a list for field {$parent}.{$field}, got " . get_debug_type($value) . '.');
            }
            $item = $type->of();
            $out = [];
            $i = 0;
            foreach ($value as $entry) {
                $itemPath = [...$path, $i];
                $out[] = $this->guard($item, $itemPath, fn () => $this->completeValue($item, $nodes, $entry, $itemPath, $parent, $field));
                $i++;
            }
            return $out;
        }
        if ($type instanceof ScalarType || $type instanceof EnumType) {
            try {
                return $type->serialize($value);
            } catch (\InvalidArgumentException $e) {
                throw new \UnexpectedValueException("Cannot serialize the value of {$parent}.{$field} as {$type}: " . $e->getMessage(), 0, $e);
            }
        }
        if ($type instanceof ObjectType) {
            $groups = [];
            foreach ($nodes as $node) {
                $this->collectFields($type, $node['selections'] ?? [], $groups, []);
            }
            return $this->executeFields($type, $groups, $value, $path);
        }
        throw new \LogicException("Cannot complete a value of type {$type}.");
    }

    /**
     * @param list<array<string,mixed>> $selections
     * @param array<string,list<array<string,mixed>>> $groups
     * @param list<string> $visited
     */
    private function collectFields(ObjectType $type, array $selections, array &$groups, array $visited): void
    {
        foreach ($selections as $s) {
            if (!$this->shouldInclude($s['directives'])) {
                continue;
            }
            if ($s['kind'] === 'field') {
                $groups[$s['alias'] ?? $s['name']][] = $s;
            } elseif ($s['kind'] === 'inline') {
                if ($s['on'] === null || $s['on'] === $type->name()) {
                    $this->collectFields($type, $s['selections'], $groups, $visited);
                }
            } elseif ($s['kind'] === 'spread' && !in_array($s['name'], $visited, true)) {
                $fragment = $this->fragments[$s['name']] ?? null;
                if ($fragment !== null && $fragment['on'] === $type->name() && $this->shouldInclude($fragment['directives'])) {
                    $this->collectFields($type, $fragment['selections'], $groups, [...$visited, $s['name']]);
                }
            }
        }
    }

    /** @param list<array{name:string,args:array<string,array>}> $directives */
    private function shouldInclude(array $directives): bool
    {
        foreach ($directives as $d) {
            $if = $this->valueFromAst(Types::nonNull(Types::boolean()), $d['args']['if'], 'if');
            if (($d['name'] === 'skip' && $if === true) || ($d['name'] === 'include' && $if === false)) {
                return false;
            }
        }
        return true;
    }

    private static function defaultResolver(string $name): \Closure
    {
        return static function (mixed $source) use ($name): mixed {
            if (is_array($source) || $source instanceof \ArrayAccess) {
                return $source[$name] ?? null;
            }
            if (is_object($source)) {
                return $source->{$name} ?? null;
            }
            return null;
        };
    }

    // ------------------------------------------------------------------ errors

    private function toError(\Throwable $e): GraphQLError
    {
        if ($e instanceof GraphQLError) {
            return new GraphQLError($e->getMessage(), $e->toArray()['extensions'] ?? [], $e);
        }
        if ($e instanceof \Naluz\Validation\ValidationException) {
            return new GraphQLError($e->getMessage(), ['code' => 'VALIDATION_FAILED', 'validation' => $e->errors], $e);
        }
        if ($e instanceof \Naluz\Database\Orm\ModelNotFoundException) {
            return new GraphQLError('Resource not found.', ['code' => 'NOT_FOUND'], $e);
        }
        if ($e instanceof \Naluz\Http\HttpException && $e->status() < 500) {
            return new GraphQLError($e->getMessage() !== '' ? $e->getMessage() : 'Request failed.', ['code' => 'HTTP_' . $e->status(), 'status' => $e->status()], $e);
        }
        if ($this->reporter !== null) {
            ($this->reporter)($e);
        }
        return $this->debug
            ? new GraphQLError($e->getMessage(), ['code' => 'INTERNAL_SERVER_ERROR', 'exception' => $e::class], $e)
            : new GraphQLError('Internal server error.', ['code' => 'INTERNAL_SERVER_ERROR'], $e);
    }

    // ------------------------------------------------------------------ input coercion

    /**
     * @param list<array<string,mixed>> $definitions
     * @param array<string,mixed> $provided
     * @return array<string,mixed>
     */
    private function coerceVariables(array $definitions, array $provided): array
    {
        $out = [];
        foreach ($definitions as $def) {
            $name = $def['name'];
            $type = $this->typeFromRef($def['type']);
            if (!array_key_exists($name, $provided)) {
                if (array_key_exists('default', $def)) {
                    $out[$name] = $this->valueFromAst($type, $def['default'], $name);
                } elseif ($type instanceof NonNullType) {
                    throw new GraphQLError("Variable \"\${$name}\" of required type \"{$type}\" was not provided.");
                }
                continue;
            }
            try {
                $out[$name] = $this->coerceInput($type, $provided[$name], $name);
            } catch (GraphQLError $e) {
                throw new GraphQLError("Variable \"\${$name}\" got invalid value " . self::short($provided[$name]) . '; ' . $e->getMessage());
            }
        }
        return $out;
    }

    /**
     * @param array<string,array<string,mixed>> $definitions
     * @param array<string,array<string,mixed>> $provided AST values
     * @return array<string,mixed>
     */
    private function coerceArguments(array $definitions, array $provided): array
    {
        $out = [];
        foreach ($definitions as $name => $def) {
            $value = self::UNSET;
            if (isset($provided[$name])) {
                try {
                    $value = $this->valueFromAst($def['type'], $provided[$name], $name);
                } catch (GraphQLError $e) {
                    throw new GraphQLError("Argument \"{$name}\": " . $e->getMessage());
                }
            }
            if ($value === self::UNSET) {
                if ($def['hasDefault']) {
                    $out[$name] = $def['default'];
                } elseif ($def['type'] instanceof NonNullType) {
                    throw new GraphQLError("Argument \"{$name}\" of required type \"{$def['type']}\" was not provided.");
                }
                continue;
            }
            $out[$name] = $value;
        }
        return $out;
    }

    private function typeFromRef(array $ref): Type
    {
        return match ($ref['kind']) {
            'list' => Types::listOf($this->typeFromRef($ref['of'])),
            'nonnull' => Types::nonNull($this->typeFromRef($ref['of'])),
            default => $this->schema->type($ref['name']) ?? throw new GraphQLError("Unknown type \"{$ref['name']}\"."),
        };
    }

    /** Client JSON -> PHP, validated against an input type. */
    private function coerceInput(Type $type, mixed $value, string $path): mixed
    {
        if ($type instanceof NonNullType) {
            if ($value === null) {
                throw new GraphQLError("Expected non-nullable type \"{$type}\" not to be null" . self::at($path) . '.');
            }
            return $this->coerceInput($type->of(), $value, $path);
        }
        if ($value === null) {
            return null;
        }
        if ($type instanceof ListType) {
            if (is_array($value) && array_is_list($value)) {
                $out = [];
                foreach ($value as $i => $item) {
                    $out[] = $this->coerceInput($type->of(), $item, "{$path}[{$i}]");
                }
                return $out;
            }
            return [$this->coerceInput($type->of(), $value, $path)];
        }
        if ($type instanceof ScalarType) {
            try {
                return $type->parseValue($value);
            } catch (\InvalidArgumentException $e) {
                throw new GraphQLError(rtrim($e->getMessage(), '.') . self::at($path) . '.');
            }
        }
        if ($type instanceof EnumType) {
            if (!is_string($value)) {
                throw new GraphQLError("Enum \"{$type}\" cannot represent a non-string value" . self::at($path) . '.');
            }
            try {
                return $type->parseName($value);
            } catch (\InvalidArgumentException $e) {
                throw new GraphQLError(rtrim($e->getMessage(), '.') . self::at($path) . '.');
            }
        }
        if ($type instanceof InputObjectType) {
            if (!is_array($value) || ($value !== [] && array_is_list($value))) {
                throw new GraphQLError("Expected type \"{$type}\" to be an object" . self::at($path) . '.');
            }
            $out = [];
            foreach ($type->fields() as $name => $field) {
                if (array_key_exists($name, $value)) {
                    $out[$name] = $this->coerceInput($field['type'], $value[$name], "{$path}.{$name}");
                } elseif ($field['hasDefault']) {
                    $out[$name] = $field['default'];
                } elseif ($field['type'] instanceof NonNullType) {
                    throw new GraphQLError("Field \"{$type}.{$name}\" of required type \"{$field['type']}\" was not provided" . self::at($path) . '.');
                }
            }
            foreach ($value as $name => $_) {
                if (!isset($type->fields()[$name])) {
                    throw new GraphQLError("Field \"{$name}\" is not defined by type \"{$type}\"" . self::at($path) . '.');
                }
            }
            return $out;
        }
        throw new GraphQLError("Cannot use type \"{$type}\" as an input.");
    }

    /** A literal from the query text -> PHP, validated against an input type. Returns UNSET for an undefined variable. */
    private function valueFromAst(Type $type, array $ast, string $path): mixed
    {
        if ($ast['kind'] === 'var') {
            if (!array_key_exists($ast['name'], $this->variables)) {
                return self::UNSET;
            }
            $value = $this->variables[$ast['name']];
            if ($value === null && $type instanceof NonNullType) {
                throw new GraphQLError("Variable \"\${$ast['name']}\" of type null was provided to non-null \"{$type}\".");
            }
            return $value;
        }
        if ($type instanceof NonNullType) {
            if ($ast['kind'] === 'null') {
                throw new GraphQLError("Expected non-nullable type \"{$type}\" not to be null" . self::at($path) . '.');
            }
            return $this->valueFromAst($type->of(), $ast, $path);
        }
        if ($ast['kind'] === 'null') {
            return null;
        }
        if ($type instanceof ListType) {
            if ($ast['kind'] === 'list') {
                $out = [];
                foreach ($ast['value'] as $i => $item) {
                    $v = $this->valueFromAst($type->of(), $item, "{$path}[{$i}]");
                    $out[] = $v === self::UNSET ? null : $v;
                }
                return $out;
            }
            $v = $this->valueFromAst($type->of(), $ast, $path);
            return $v === self::UNSET ? self::UNSET : [$v];
        }
        if ($type instanceof ScalarType) {
            try {
                return $type->parseLiteral($ast);
            } catch (\InvalidArgumentException $e) {
                throw new GraphQLError(rtrim($e->getMessage(), '.') . self::at($path) . '.');
            }
        }
        if ($type instanceof EnumType) {
            if ($ast['kind'] !== 'enum') {
                throw new GraphQLError("Enum \"{$type}\" cannot represent a non-enum value" . self::at($path) . '.');
            }
            try {
                return $type->parseName($ast['value']);
            } catch (\InvalidArgumentException $e) {
                throw new GraphQLError(rtrim($e->getMessage(), '.') . self::at($path) . '.');
            }
        }
        if ($type instanceof InputObjectType) {
            if ($ast['kind'] !== 'object') {
                throw new GraphQLError("Expected type \"{$type}\" to be an object" . self::at($path) . '.');
            }
            $out = [];
            foreach ($type->fields() as $name => $field) {
                $v = isset($ast['value'][$name]) ? $this->valueFromAst($field['type'], $ast['value'][$name], "{$path}.{$name}") : self::UNSET;
                if ($v !== self::UNSET) {
                    $out[$name] = $v;
                } elseif ($field['hasDefault']) {
                    $out[$name] = $field['default'];
                } elseif ($field['type'] instanceof NonNullType) {
                    throw new GraphQLError("Field \"{$type}.{$name}\" of required type \"{$field['type']}\" was not provided" . self::at($path) . '.');
                }
            }
            foreach (array_keys($ast['value']) as $name) {
                if (!isset($type->fields()[$name])) {
                    throw new GraphQLError("Field \"{$name}\" is not defined by type \"{$type}\"" . self::at($path) . '.');
                }
            }
            return $out;
        }
        throw new GraphQLError("Cannot use type \"{$type}\" as an input.");
    }

    private static function at(string $path): string
    {
        return str_contains($path, '.') || str_contains($path, '[') ? " at \"{$path}\"" : '';
    }

    private static function short(mixed $value): string
    {
        $json = (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return mb_strlen($json) > 120 ? mb_substr($json, 0, 117) . '...' : $json;
    }
}
