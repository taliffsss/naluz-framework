<?php

declare(strict_types=1);

namespace Naluz\GraphQL\Type;

/**
 * `new InputObjectType('PostInput', ['title' => Types::nonNull(Types::string()), 'tags' => ['type' => Types::listOf(Types::string()), 'default' => []]])`
 */
final class InputObjectType extends Type
{
    /** @var array<string,array{name:string,type:Type,default:mixed,hasDefault:bool,description:?string}>|null */
    private ?array $resolved = null;

    /** @param array<string,mixed>|\Closure():array<string,mixed> $fields */
    public function __construct(private readonly string $name, private readonly array|\Closure $fields, public readonly ?string $description = null)
    {
        Type::assertName($name, 'input object');
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function __toString(): string
    {
        return $this->name;
    }

    /** @return array<string,array{name:string,type:Type,default:mixed,hasDefault:bool,description:?string}> */
    public function fields(): array
    {
        if ($this->resolved === null) {
            $defs = $this->fields instanceof \Closure ? ($this->fields)() : $this->fields;
            $out = [];
            foreach ($defs as $fname => $def) {
                Type::assertName((string) $fname, 'input field');
                $out[$fname] = self::normalize((string) $fname, $def);
            }
            $this->resolved = $out;
        }
        return $this->resolved;
    }

    /** @return array{name:string,type:Type,default:mixed,hasDefault:bool,description:?string} */
    public static function normalize(string $name, mixed $def): array
    {
        if ($def instanceof Type || $def instanceof \Closure) {
            $def = ['type' => $def];
        }
        $type = Type::resolve($def['type'] ?? throw new \InvalidArgumentException("Input [{$name}] needs a type."));
        $named = $type->named();
        if (!$named instanceof ScalarType && !$named instanceof EnumType && !$named instanceof InputObjectType) {
            throw new \InvalidArgumentException("Input [{$name}] must have an input type (scalar, enum or input object).");
        }
        return [
            'name' => $name,
            'type' => $type,
            'default' => $def['default'] ?? null,
            'hasDefault' => array_key_exists('default', $def),
            'description' => $def['description'] ?? null,
        ];
    }
}
