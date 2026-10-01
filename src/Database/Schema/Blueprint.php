<?php

declare(strict_types=1);

namespace Naluz\Database\Schema;

final class Blueprint
{
    /** @var list<ColumnDefinition> */
    public array $columns = [];
    /** @var list<array{columns:list<string>,unique:bool,name:?string}> */
    public array $indexes = [];
    /** @var list<string> */
    public array $dropColumns = [];

    public function __construct(public readonly string $table)
    {
    }

    private function add(string $type, string $name, array $params = []): ColumnDefinition
    {
        return $this->columns[] = new ColumnDefinition($type, $name, $params);
    }

    public function id(string $name = 'id'): ColumnDefinition
    {
        return $this->add('id', $name);
    }

    public function string(string $name, int $length = 255): ColumnDefinition
    {
        return $this->add('string', $name, ['length' => $length]);
    }

    public function text(string $name): ColumnDefinition
    {
        return $this->add('text', $name);
    }

    public function integer(string $name): ColumnDefinition
    {
        return $this->add('integer', $name);
    }

    public function bigInteger(string $name): ColumnDefinition
    {
        return $this->add('bigInteger', $name);
    }

    public function boolean(string $name): ColumnDefinition
    {
        return $this->add('boolean', $name);
    }

    public function decimal(string $name, int $precision = 10, int $scale = 2): ColumnDefinition
    {
        return $this->add('decimal', $name, compact('precision', 'scale'));
    }

    public function float(string $name): ColumnDefinition
    {
        return $this->add('float', $name);
    }

    public function json(string $name): ColumnDefinition
    {
        return $this->add('json', $name);
    }

    public function date(string $name): ColumnDefinition
    {
        return $this->add('date', $name);
    }

    public function timestamp(string $name): ColumnDefinition
    {
        return $this->add('timestamp', $name);
    }

    public function foreignId(string $name): ColumnDefinition
    {
        return $this->add('bigInteger', $name)->unsigned();
    }

    public function timestamps(): void
    {
        $this->timestamp('created_at')->nullable();
        $this->timestamp('updated_at')->nullable();
    }

    public function softDeletes(string $name = 'deleted_at'): ColumnDefinition
    {
        return $this->timestamp($name)->nullable();
    }

    /** @param string|list<string> $columns */
    public function index(string|array $columns, ?string $name = null): void
    {
        $this->indexes[] = ['columns' => (array) $columns, 'unique' => false, 'name' => $name];
    }

    /** @param string|list<string> $columns */
    public function unique(string|array $columns, ?string $name = null): void
    {
        $this->indexes[] = ['columns' => (array) $columns, 'unique' => true, 'name' => $name];
    }

    public function dropColumn(string ...$columns): void
    {
        array_push($this->dropColumns, ...$columns);
    }
}
