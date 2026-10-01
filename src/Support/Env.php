<?php

declare(strict_types=1);

namespace Naluz\Support;

/**
 * Minimal, dependency-free .env loader.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $values = [];
    /** @var array<string,string> */
    private static array $explicit = [];

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        foreach (self::parse((string) file_get_contents($path)) as $key => $value) {
            self::$values[$key] = $value;
        }
    }

    /** @return array<string,string> */
    public static function parse(string $contents): array
    {
        $out = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim(preg_replace('/^export\s+/', '', trim($key)) ?? '');
            $value = trim($value);
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $quote = $value[0];
                $end = strrpos($value, $quote);
                $value = $end > 0 ? substr($value, 1, $end - 1) : substr($value, 1);
                if ($quote === '"') {
                    $value = str_replace(['\\n', '\\"'], ["\n", '"'], $value);
                }
            } else {
                $value = trim(preg_replace('/\s+#.*$/', '', $value) ?? $value);
            }
            $out[$key] = $value;
        }
        return $out;
    }

    public static function set(string $key, string $value): void
    {
        self::$explicit[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        // precedence: Env::set() > real environment (CI, php-fpm, docker) > .env file
        $value = self::$explicit[$key] ?? $_ENV[$key] ?? (getenv($key) !== false ? getenv($key) : null) ?? self::$values[$key] ?? null;
        if ($value === null) {
            return $default instanceof \Closure ? $default() : $default;
        }
        return match (strtolower((string) $value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }

    public static function flush(): void
    {
        self::$values = [];
        self::$explicit = [];
    }
}
