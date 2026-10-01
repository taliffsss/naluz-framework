<?php

declare(strict_types=1);

namespace Naluz\Log;

/** One JSON object per line — ready for Loki, Elastic, Datadog, CloudWatch… */
final class JsonFormatter implements Formatter
{
    public function format(string $level, string $message, array $context): string
    {
        $replace = [];
        $extra = [];
        $exception = null;
        foreach ($context as $key => $value) {
            if ($key === 'exception' && $value instanceof \Throwable) {
                $exception = [
                    'class' => $value::class,
                    'message' => $value->getMessage(),
                    'file' => $value->getFile(),
                    'line' => $value->getLine(),
                ];
                continue;
            }
            $extra[$key] = is_scalar($value) || $value === null ? $value
                : ($value instanceof \Stringable ? (string) $value : '[' . get_debug_type($value) . ']');
            if (is_scalar($value) || $value instanceof \Stringable || $value === null) {
                $replace['{' . $key . '}'] = (string) $value;
            }
        }
        $record = [
            'time' => date(DATE_ATOM),
            'level' => $level,
            'message' => strtr($message, $replace),
            'context' => $extra,
        ];
        if ($exception !== null) {
            $record['exception'] = $exception;
        }
        return json_encode(
            $record,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        ) . "\n";
    }
}
