<?php

declare(strict_types=1);

namespace Naluz\Console;

class Output
{
    /** @var resource */
    private $stream;

    /** @param resource|null $stream */
    public function __construct($stream = null)
    {
        $this->stream = $stream ?? STDOUT;
    }

    public function line(string $text = ''): void
    {
        fwrite($this->stream, $text . PHP_EOL);
    }

    public function info(string $text): void
    {
        $this->line($this->color("32", $text));
    }

    public function error(string $text): void
    {
        $this->line($this->color("31", $text));
    }

    public function warn(string $text): void
    {
        $this->line($this->color("33", $text));
    }

    /** @param list<string> $headers @param list<list<string>> $rows */
    public function table(array $headers, array $rows): void
    {
        $widths = array_map('strlen', $headers);
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, strlen((string) $cell));
            }
        }
        $fmt = fn (array $r) => '| ' . implode(' | ', array_map(fn ($c, $i) => str_pad((string) $c, $widths[$i]), $r, array_keys($r))) . ' |';
        $sep = '+-' . implode('-+-', array_map(fn ($w) => str_repeat('-', $w), $widths)) . '-+';
        $this->line($sep);
        $this->line($fmt($headers));
        $this->line($sep);
        foreach ($rows as $row) {
            $this->line($fmt($row));
        }
        $this->line($sep);
    }

    private function color(string $code, string $text): string
    {
        return stream_isatty($this->stream) ? "\033[{$code}m{$text}\033[0m" : $text;
    }
}
