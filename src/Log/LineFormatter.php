<?php

declare(strict_types=1);

namespace Naluz\Log;

/** `[2026-01-01 12:00:00] ERROR: message (RuntimeException: boom at file.php:12)` — one line per record. */
final class LineFormatter implements Formatter
{
    public function format(string $level, string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value instanceof \Stringable || $value === null) {
                // strip newlines so user-controlled values cannot forge log lines
                $replace['{' . $key . '}'] = str_replace(["\r", "\n"], ' ', (string) $value);
            }
        }
        $line = sprintf(
            '[%s] %s: %s',
            date('Y-m-d H:i:s'),
            strtoupper($level),
            strtr(str_replace(["\r", "\n"], ' ', $message), $replace)
        );
        $e = $context['exception'] ?? null;
        if ($e instanceof \Throwable) {
            $line .= sprintf(
                ' (%s: %s at %s:%d)',
                $e::class,
                str_replace(["\r", "\n"], ' ', $e->getMessage()),
                $e->getFile(),
                $e->getLine()
            );
        }
        return $line . "\n";
    }
}
