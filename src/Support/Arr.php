<?php

declare(strict_types=1);

namespace Naluz\Support;

/**
 * Array helpers with dot-notation access.
 */
final class Arr
{
    public static function get(array $array, string|int|null $key, mixed $default = null): mixed
    {
        if ($key === null) {
            return $array;
        }
        if (array_key_exists($key, $array)) {
            return $array[$key];
        }
        foreach (explode('.', (string) $key) as $segment) {
            if (is_array($array) && array_key_exists($segment, $array)) {
                $array = $array[$segment];
            } else {
                return $default instanceof \Closure ? $default() : $default;
            }
        }
        return $array;
    }

    public static function has(array $array, string $key): bool
    {
        $marker = new \stdClass();
        return self::get($array, $key, $marker) !== $marker;
    }

    public static function set(array &$array, string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        while (count($segments) > 1) {
            $segment = array_shift($segments);
            if (!isset($array[$segment]) || !is_array($array[$segment])) {
                $array[$segment] = [];
            }
            $array = &$array[$segment];
        }
        $array[array_shift($segments)] = $value;
    }

    public static function forget(array &$array, string $key): void
    {
        $segments = explode('.', $key);
        while (count($segments) > 1) {
            $segment = array_shift($segments);
            if (!isset($array[$segment]) || !is_array($array[$segment])) {
                return;
            }
            $array = &$array[$segment];
        }
        unset($array[array_shift($segments)]);
    }

    /** @param list<string> $keys */
    public static function only(array $array, array $keys): array
    {
        return array_intersect_key($array, array_flip($keys));
    }

    /** @param list<string> $keys */
    public static function except(array $array, array $keys): array
    {
        return array_diff_key($array, array_flip($keys));
    }

    public static function isList(array $array): bool
    {
        return array_is_list($array);
    }
}
