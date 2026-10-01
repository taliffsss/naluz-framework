<?php

declare(strict_types=1);

namespace Naluz\GraphQL;

use Naluz\GraphQL\Type\ObjectType;
use Naluz\GraphQL\Type\Type;

/** Passed to every resolver as the 4th argument. */
final class ResolveInfo
{
    /**
     * @param list<string|int> $path
     * @param list<array<string,mixed>> $fieldNodes
     * @param array<string,mixed> $variables coerced variable values
     * @param array<string,array<string,mixed>> $fragments
     */
    public function __construct(
        public readonly string $fieldName,
        public readonly ObjectType $parentType,
        public readonly Type $returnType,
        public readonly array $path,
        public readonly array $fieldNodes,
        public readonly array $variables,
        private readonly array $fragments,
        public readonly Schema $schema
    ) {
    }

    /**
     * Names of the fields the client selected under this field (fragments expanded). Handy for eager loading:
     * `in_array('author', $info->subfields(), true)` → `->with('author')`.
     *
     * @return list<string>
     */
    public function subfields(): array
    {
        $names = [];
        foreach ($this->fieldNodes as $node) {
            $this->walk($node['selections'] ?? [], $names, []);
        }
        return array_values(array_unique($names));
    }

    /** @param list<array<string,mixed>> $selections */
    private function walk(array $selections, array &$names, array $seen): void
    {
        foreach ($selections as $s) {
            if ($s['kind'] === 'field') {
                $names[] = $s['name'];
            } elseif ($s['kind'] === 'inline') {
                $this->walk($s['selections'], $names, $seen);
            } elseif ($s['kind'] === 'spread' && !isset($seen[$s['name']]) && isset($this->fragments[$s['name']])) {
                $this->walk($this->fragments[$s['name']]['selections'], $names, $seen + [$s['name'] => true]);
            }
        }
    }
}
