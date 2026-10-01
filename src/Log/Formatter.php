<?php

declare(strict_types=1);

namespace Naluz\Log;

interface Formatter
{
    /** @param array<string,mixed> $context @return string one complete record, including the trailing newline */
    public function format(string $level, string $message, array $context): string;
}
