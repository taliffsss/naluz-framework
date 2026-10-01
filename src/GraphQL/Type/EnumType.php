<?php

declare(strict_types=1);

namespace Naluz\GraphQL\Type;

/** `new EnumType('Role', ['ADMIN' => 'admin', 'USER' => ['value' => 'user', 'description' => '…']])` */
final class EnumType extends Type
{
    /** @var array<string,array{name:string,value:mixed,description:?string,deprecated:?string}> */
    private array $values = [];

    /** @param array<string,mixed> $values GraphQL name => internal value (or a definition array) */
    public function __construct(private readonly string $name, array $values, public readonly ?string $description = null, bool $internal = false)
    {
        if (!$internal) {
            Type::assertName($name, 'enum');
        }
        if ($values === []) {
            throw new \InvalidArgumentException("Enum [{$name}] must define at least one value.");
        }
        foreach ($values as $key => $def) {
            $vname = (string) $key;
            if (!preg_match('/^[_A-Za-z][_0-9A-Za-z]*$/', $vname) || in_array($vname, ['true', 'false', 'null'], true)) {
                throw new \InvalidArgumentException("Invalid enum value name [{$vname}] in [{$name}].");
            }
            $isDef = is_array($def) && array_key_exists('value', $def);
            $this->values[$vname] = [
                'name' => $vname,
                'value' => $isDef ? $def['value'] : $def,
                'description' => $isDef ? ($def['description'] ?? null) : null,
                'deprecated' => $isDef ? ($def['deprecated'] ?? null) : null,
            ];
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

    /** @return array<string,array{name:string,value:mixed,description:?string,deprecated:?string}> */
    public function values(): array
    {
        return $this->values;
    }

    /** Client name -> internal value. */
    public function parseName(string $name): mixed
    {
        if (!isset($this->values[$name])) {
            throw new \InvalidArgumentException("Value \"{$name}\" does not exist in the {$this->name} enum.");
        }
        return $this->values[$name]['value'];
    }

    /** Internal value -> client name. */
    public function serialize(mixed $value): string
    {
        foreach ($this->values as $name => $def) {
            if ($def['value'] === $value) {
                return $name;
            }
        }
        throw new \InvalidArgumentException("Enum {$this->name} cannot represent the internal value.");
    }
}
