<?php

declare(strict_types=1);

namespace Naluz\NoSql\Engine;

/** Dotted-path access into nested documents: `address.city`, `tags.0`. Lists are traversed element by element. */
final class Path
{
    /**
     * @param-out bool $exists
     * @return list<mixed> every value reachable at the path
     */
    public static function values(mixed $node, string $path, ?bool &$exists = null): array
    {
        $found = self::walk($node, explode('.', $path));
        $exists = $found !== null;
        return $found ?? [];
    }

    /** @return list<mixed>|null null = the path does not exist */
    private static function walk(mixed $node, array $parts): ?array
    {
        if ($parts === []) {
            return [$node];
        }
        $part = array_shift($parts);
        if (!is_array($node)) {
            return null;
        }
        if (array_is_list($node) && $node !== [] && !ctype_digit($part)) {
            $out = [];
            $any = false;
            foreach ($node as $element) {
                $r = self::walk($element, [$part, ...$parts]);
                if ($r !== null) {
                    $any = true;
                    array_push($out, ...$r);
                }
            }
            return $any ? $out : null;
        }
        $key = array_key_exists($part, $node) ? $part : (ctype_digit($part) && array_key_exists((int) $part, $node) ? (int) $part : null);
        if ($key === null) {
            return null;
        }
        return self::walk($node[$key], $parts); // note: a stored null is a value, not "missing"
    }

    public static function first(array $doc, string $path): mixed
    {
        return self::values($doc, $path)[0] ?? null;
    }

    public static function set(array &$doc, string $path, mixed $value): void
    {
        $parts = explode('.', $path);
        $ref = &$doc;
        foreach ($parts as $i => $part) {
            if ($i === count($parts) - 1) {
                $ref[$part] = $value;
                return;
            }
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = [];
            }
            $ref = &$ref[$part];
        }
    }

    public static function unset(array &$doc, string $path): void
    {
        $parts = explode('.', $path);
        $ref = &$doc;
        foreach ($parts as $i => $part) {
            if (!is_array($ref) || !array_key_exists($part, $ref)) {
                return;
            }
            if ($i === count($parts) - 1) {
                unset($ref[$part]);
                return;
            }
            $ref = &$ref[$part];
        }
    }

    public static function has(array $doc, string $path): bool
    {
        self::values($doc, $path, $exists);
        return (bool) $exists;
    }
}
