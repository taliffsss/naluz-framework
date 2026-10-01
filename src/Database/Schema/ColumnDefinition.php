<?php

declare(strict_types=1);

namespace Naluz\Database\Schema;

final class ColumnDefinition
{
    public bool $nullable = false;
    public bool $unsigned = false;
    public bool $unique = false;
    public bool $index = false;
    public mixed $default = null;
    public bool $hasDefault = false;
    public bool $useCurrent = false;
    public ?array $foreign = null;

    public function __construct(public readonly string $type, public readonly string $name, public readonly array $params = [])
    {
    }

    public function nullable(bool $value = true): self
    {
        $this->nullable = $value;
        return $this;
    }

    public function default(mixed $value): self
    {
        $this->default = $value;
        $this->hasDefault = true;
        return $this;
    }

    public function useCurrent(): self
    {
        $this->useCurrent = true;
        return $this;
    }

    public function unsigned(): self
    {
        $this->unsigned = true;
        return $this;
    }

    public function unique(): self
    {
        $this->unique = true;
        return $this;
    }

    public function index(): self
    {
        $this->index = true;
        return $this;
    }

    /** Declare `FOREIGN KEY (this) REFERENCES table(column)`. */
    public function constrained(string $table, string $column = 'id', string $onDelete = 'restrict'): self
    {
        $this->foreign = ['table' => $table, 'column' => $column, 'onDelete' => $onDelete];
        return $this;
    }

    public function cascadeOnDelete(): self
    {
        if ($this->foreign !== null) {
            $this->foreign['onDelete'] = 'cascade';
        }
        return $this;
    }
}
