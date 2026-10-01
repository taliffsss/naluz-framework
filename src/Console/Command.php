<?php

declare(strict_types=1);

namespace Naluz\Console;

use Naluz\Foundation\Application;

abstract class Command
{
    public function __construct(protected readonly Application $app)
    {
    }

    abstract public function name(): string;

    abstract public function description(): string;

    /** @return int exit code */
    abstract public function handle(Input $input, Output $output): int;
}
