<?php

declare(strict_types=1);

namespace Naluz\Database\Query;

/** A raw SQL fragment, inserted verbatim. Never build one from user input. */
final class Expression implements \Stringable
{
    public function __construct(private readonly string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
