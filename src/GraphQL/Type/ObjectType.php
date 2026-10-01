<?php

declare(strict_types=1);

namespace Naluz\GraphQL\Type;

/**
 * An output type. Each field is a type, or an array:
 *
 *     'posts' => [
 *         'type' => Types::listOf(Types::nonNull($postType)),
 *         'args' => ['first' => ['type' => Types::int(), 'default' => 10]],
 *         'resolve' => fn ($source, array $args, $context, ResolveInfo $info) => …,
 *     ]
 *
 * Fields without `resolve` read the property / array key of the same name from the parent value.
 */
final class ObjectType extends Type
{
    /** @var array<string,array<string,mixed>>|null */
    private ?array $resolved = null;

    /** @param array<string,mixed>|\Closure():array<string,mixed> $fields */
    public function __construct(private readonly string $name, private readonly array|\Closure $fields, public readonly ?string $description = null, private readonly bool $internal = false)
    {
        if (!$internal) {
            Type::assertName($name, 'object');
        }
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function __toString(): string
    {
        return $this->name;
    }

    /** @return array<string,array{name:string,type:Type,args:array<string,array{name:string,type:Type,default:mixed,hasDefault:bool,description:?string}>,resolve:?callable,description:?string,deprecated:?string}> */
    public function fields(): array
    {
        if ($this->resolved === null) {
            $defs = $this->fields instanceof \Closure ? ($this->fields)() : $this->fields;
            if ($defs === []) {
                throw new \InvalidArgumentException("Object type [{$this->name}] must define at least one field.");
            }
            $out = [];
            foreach ($defs as $fname => $def) {
                if (!$this->internal) {
                    Type::assertName((string) $fname, 'field');
                }
                if ($def instanceof Type || $def instanceof \Closure) {
                    $def = ['type' => $def];
                }
                $type = Type::resolve($def['type'] ?? throw new \InvalidArgumentException("Field [{$this->name}.{$fname}] needs a type."));
                if ($type->named() instanceof InputObjectType) {
                    throw new \InvalidArgumentException("Field [{$this->name}.{$fname}] cannot return an input object type.");
                }
                $args = [];
                foreach ($def['args'] ?? [] as $aname => $adef) {
                    Type::assertName((string) $aname, 'argument');
                    $args[$aname] = InputObjectType::normalize((string) $aname, $adef);
                }
                $out[$fname] = [
                    'name' => (string) $fname,
                    'type' => $type,
                    'args' => $args,
                    'resolve' => $def['resolve'] ?? null,
                    'description' => $def['description'] ?? null,
                    'deprecated' => $def['deprecated'] ?? null,
                ];
            }
            $this->resolved = $out;
        }
        return $this->resolved;
    }
}
