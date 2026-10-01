<?php

declare(strict_types=1);

namespace Naluz\Console;

final class Input
{
    /** @var list<string> */
    public readonly array $arguments;
    /** @var array<string,string|true> */
    public readonly array $options;

    /** @param list<string> $argv arguments after the command name */
    public function __construct(array $argv)
    {
        $args = [];
        $opts = [];
        foreach ($argv as $token) {
            if (str_starts_with($token, '--')) {
                [$k, $v] = array_pad(explode('=', substr($token, 2), 2), 2, true);
                $opts[$k] = $v;
            } else {
                $args[] = $token;
            }
        }
        $this->arguments = $args;
        $this->options = $opts;
    }

    public function argument(int $i, ?string $default = null): ?string
    {
        return $this->arguments[$i] ?? $default;
    }

    public function option(string $name, string|bool|null $default = null): string|bool|null
    {
        return $this->options[$name] ?? $default;
    }
}
