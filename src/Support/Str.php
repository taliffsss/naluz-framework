<?php

declare(strict_types=1);

namespace Naluz\Support;

final class Str
{
    public static function snake(string $value): string
    {
        $value = preg_replace('/(?<=[a-z0-9])(?=[A-Z])|[\s\-]+/', '_', $value) ?? $value;
        return strtolower($value);
    }

    public static function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }

    public static function camel(string $value): string
    {
        return lcfirst(self::studly($value));
    }

    public static function slug(string $value, string $separator = '-'): string
    {
        $value = strtolower(trim((string) preg_replace('/[^\pL\pN]+/u', $separator, $value), $separator));
        return $value;
    }

    /** Naive English pluralisation, enough for table names. Override `$table` on a model otherwise. */
    public static function plural(string $word): string
    {
        $irregular = ['person' => 'people', 'child' => 'children', 'man' => 'men', 'woman' => 'women'];
        $lower = strtolower($word);
        foreach ($irregular as $singular => $plural) {
            if (str_ends_with($lower, $singular)) {
                return substr($word, 0, -strlen($singular)) . $plural;
            }
        }
        if (preg_match('/(s|x|z|ch|sh)$/i', $word)) {
            return $word . 'es';
        }
        if (preg_match('/[^aeiou]y$/i', $word)) {
            return substr($word, 0, -1) . 'ies';
        }
        return $word . 's';
    }

    public static function singular(string $word): string
    {
        return match (true) {
            (bool) preg_match('/[^aeiou]ies$/i', $word) => substr($word, 0, -3) . 'y',
            (bool) preg_match('/(s|x|z|ch|sh)es$/i', $word) => substr($word, 0, -2),
            str_ends_with($word, 's') && !str_ends_with($word, 'ss') => substr($word, 0, -1),
            default => $word,
        };
    }

    public static function random(int $length = 32): string
    {
        return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
    }

    public static function classBasename(string|object $class): string
    {
        $class = is_object($class) ? $class::class : $class;
        return basename(str_replace('\\', '/', $class));
    }
}
