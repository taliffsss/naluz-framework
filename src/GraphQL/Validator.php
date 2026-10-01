<?php

declare(strict_types=1);

namespace Naluz\GraphQL;

use Naluz\GraphQL\Type\EnumType;
use Naluz\GraphQL\Type\InputObjectType;
use Naluz\GraphQL\Type\NonNullType;
use Naluz\GraphQL\Type\ObjectType;
use Naluz\GraphQL\Type\ScalarType;
use Naluz\GraphQL\Type\Type;

/**
 * Checks a parsed request against the schema before anything runs, and enforces the depth and size limits that
 * stop a single request from doing unbounded work (deeply nested selections, alias / fragment amplification).
 */
final class Validator
{
    private int $nodes = 0;
    /** @var list<GraphQLError> */
    private array $errors = [];
    /** @var array<string,array<string,mixed>> */
    private array $fragments = [];
    /** @var array<string,true> */
    private array $variables = [];

    public function __construct(
        private readonly Schema $schema,
        private readonly int $maxDepth = 10,
        private readonly int $maxNodes = 500,
        private readonly bool $introspection = true
    ) {
    }

    /**
     * @param array{operations:list<array<string,mixed>>,fragments:array<string,array<string,mixed>>} $document
     * @param array<string,mixed> $operation the operation chosen to run
     * @return list<GraphQLError>
     */
    public function validate(array $document, array $operation): array
    {
        $this->nodes = 0;
        $this->errors = [];
        $this->fragments = $document['fragments'];
        $this->variables = [];

        $root = match ($operation['type']) {
            'query' => $this->schema->query,
            'mutation' => $this->schema->mutation ?? $this->fail($operation['loc'], 'Schema is not configured for mutations.'),
            default => $this->fail($operation['loc'], 'Subscriptions are not supported.'),
        };
        if (!$root instanceof ObjectType) {
            return $this->errors;
        }

        foreach ($operation['variables'] as $var) {
            $this->variables[$var['name']] = true;
            $named = $this->schema->type(self::baseName($var['type']));
            if ($named === null) {
                $this->error($var['loc'], "Unknown type \"" . self::baseName($var['type']) . "\" for variable \"\${$var['name']}\".");
            } elseif (!$named instanceof ScalarType && !$named instanceof EnumType && !$named instanceof InputObjectType) {
                $this->error($var['loc'], "Variable \"\${$var['name']}\" cannot be non-input type \"{$named}\".");
            }
            if (isset($var['default'])) {
                $this->walkValue($var['default'], $var['loc'], false);
            }
        }
        $this->directives($operation['directives'], $operation['loc']);

        try {
            $this->selectionSet($root, $operation['selections'], 1, [], true);
        } catch (\LengthException) {
            // limit reached: the error is already recorded
        }
        return $this->errors;
    }

    /**
     * @param list<array<string,mixed>> $selections
     * @param list<string> $stack fragments currently being expanded
     */
    private function selectionSet(ObjectType $parent, array $selections, int $depth, array $stack, bool $isRoot, array &$seen = []): void
    {
        foreach ($selections as $s) {
            switch ($s['kind']) {
                case 'field':
                    $this->field($parent, $s, $depth, $stack, $isRoot, $seen);
                    break;
                case 'inline':
                    $this->directives($s['directives'], $s['loc']);
                    if ($s['on'] !== null) {
                        $this->typeCondition($parent, $s['on'], $s['loc'], 'Fragment');
                    }
                    $this->selectionSet($parent, $s['selections'], $depth, $stack, $isRoot, $seen);
                    break;
                case 'spread':
                    $this->directives($s['directives'], $s['loc']);
                    $this->spread($parent, $s, $depth, $stack, $isRoot, $seen);
                    break;
            }
        }
    }

    /** @param array<string,mixed> $s */
    private function field(ObjectType $parent, array $s, int $depth, array $stack, bool $isRoot, array &$seen): void
    {
        if (++$this->nodes > $this->maxNodes) {
            $this->error($s['loc'], "The query is too large: it selects more than {$this->maxNodes} fields (after expanding fragments).");
            throw new \LengthException();
        }
        if ($depth > $this->maxDepth) {
            $this->error($s['loc'], "The query is too deep: the maximum depth is {$this->maxDepth}.");
            throw new \LengthException();
        }
        $this->directives($s['directives'], $s['loc']);

        $key = $s['alias'] ?? $s['name'];
        $signature = $s['name'] . json_encode($s['args']);
        if (isset($seen[$key]) && $seen[$key] !== $signature) {
            $this->error($s['loc'], "Fields \"{$key}\" conflict because they have different names or arguments. Use an alias.");
        }
        $seen[$key] = $signature;

        if ($s['name'] === '__typename') {
            if ($s['args'] !== [] || $s['selections'] !== null) {
                $this->error($s['loc'], 'Field "__typename" takes no arguments and cannot have a selection.');
            }
            return;
        }

        $def = $parent->fields()[$s['name']] ?? null;
        if ($def === null && $isRoot && $depth === 1 && ($s['name'] === '__schema' || $s['name'] === '__type')) {
            if (!$this->introspection) {
                $this->error($s['loc'], 'GraphQL introspection is disabled on this server.');
                return;
            }
            $def = Introspection::rootFields($this->schema)[$s['name']];
        }
        if ($def === null) {
            $this->error($s['loc'], "Cannot query field \"{$s['name']}\" on type \"{$parent}\".");
            return;
        }

        foreach ($s['args'] as $name => $ast) {
            if (!isset($def['args'][$name])) {
                $this->error($s['loc'], "Unknown argument \"{$name}\" on field \"{$parent}.{$s['name']}\".");
                continue;
            }
            $this->walkValue($ast, $s['loc'], false);
        }
        foreach ($def['args'] as $name => $arg) {
            if ($arg['type'] instanceof NonNullType && !$arg['hasDefault'] && !isset($s['args'][$name])) {
                $this->error($s['loc'], "Field \"{$parent}.{$s['name']}\" argument \"{$name}\" of type \"{$arg['type']}\" is required, but it was not provided.");
            }
        }

        $named = $def['type']->named();
        if ($named instanceof ObjectType) {
            if ($s['selections'] === null) {
                $this->error($s['loc'], "Field \"{$s['name']}\" of type \"{$def['type']}\" must have a selection of subfields.");
                return;
            }
            $childSeen = [];
            $this->selectionSet($named, $s['selections'], $depth + 1, $stack, false, $childSeen);
        } elseif ($s['selections'] !== null) {
            $this->error($s['loc'], "Field \"{$s['name']}\" must not have a selection since type \"{$def['type']}\" has no subfields.");
        }
    }

    /** @param array<string,mixed> $s */
    private function spread(ObjectType $parent, array $s, int $depth, array $stack, bool $isRoot, array &$seen): void
    {
        $fragment = $this->fragments[$s['name']] ?? null;
        if ($fragment === null) {
            $this->error($s['loc'], "Unknown fragment \"{$s['name']}\".");
            return;
        }
        if (in_array($s['name'], $stack, true)) {
            $this->error($s['loc'], "Cannot spread fragment \"{$s['name']}\" within itself.");
            return;
        }
        $this->typeCondition($parent, $fragment['on'], $s['loc'], "Fragment \"{$s['name']}\"");
        $this->directives($fragment['directives'], $fragment['loc']);
        $this->selectionSet($parent, $fragment['selections'], $depth, [...$stack, $s['name']], $isRoot, $seen);
    }

    private function typeCondition(ObjectType $parent, string $on, array $loc, string $what): void
    {
        if ($this->schema->type($on) === null) {
            $this->error($loc, "{$what} references unknown type \"{$on}\".");
        } elseif ($on !== $parent->name()) {
            $this->error($loc, "{$what} cannot be spread here as objects of type \"{$parent}\" can never be of type \"{$on}\".");
        }
    }

    /** @param list<array{name:string,args:array<string,array>}> $directives */
    private function directives(array $directives, array $loc): void
    {
        foreach ($directives as $d) {
            if ($d['name'] !== 'skip' && $d['name'] !== 'include') {
                $this->error($loc, "Unknown directive \"@{$d['name']}\".");
                continue;
            }
            if (array_keys($d['args']) !== ['if']) {
                $this->error($loc, "Directive \"@{$d['name']}\" requires exactly one argument, \"if\".");
                continue;
            }
            $this->walkValue($d['args']['if'], $loc, false);
        }
    }

    /** Flags undefined variables anywhere inside a value. */
    private function walkValue(array $ast, array $loc, bool $const): void
    {
        switch ($ast['kind']) {
            case 'var':
                if (!isset($this->variables[$ast['name']])) {
                    $this->error($loc, "Variable \"\${$ast['name']}\" is not defined.");
                }
                break;
            case 'list':
                foreach ($ast['value'] as $v) {
                    $this->walkValue($v, $loc, $const);
                }
                break;
            case 'object':
                foreach ($ast['value'] as $v) {
                    $this->walkValue($v, $loc, $const);
                }
                break;
        }
    }

    private static function baseName(array $ref): string
    {
        while ($ref['kind'] !== 'named') {
            $ref = $ref['of'];
        }
        return $ref['name'];
    }

    private function error(array $loc, string $message): void
    {
        $this->errors[] = (new GraphQLError($message))->at($loc['line'], $loc['column']);
    }

    private function fail(array $loc, string $message): null
    {
        $this->error($loc, $message);
        return null;
    }
}
