<?php

declare(strict_types=1);

namespace Naluz\GraphQL;

/**
 * An error that is safe to show to API clients. Anything else thrown while resolving a field is reported as
 * "Internal server error" (the real message is only included when debug is on).
 */
class GraphQLError extends \Exception
{
    /** @var list<string|int> */
    public array $path = [];
    /** @var list<array{line:int,column:int}> */
    public array $locations = [];

    public function __construct(string $message, private readonly array $extensions = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** @param list<string|int> $path */
    public function atPath(array $path): static
    {
        $this->path = $path;
        return $this;
    }

    public function at(int $line, int $column): static
    {
        $this->locations = [['line' => $line, 'column' => $column]];
        return $this;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $out = ['message' => $this->getMessage()];
        if ($this->locations !== []) {
            $out['locations'] = $this->locations;
        }
        if ($this->path !== []) {
            $out['path'] = $this->path;
        }
        if ($this->extensions !== []) {
            $out['extensions'] = $this->extensions;
        }
        return $out;
    }
}
