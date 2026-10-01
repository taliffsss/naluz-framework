<?php

declare(strict_types=1);

namespace Naluz\NoSql\Engine;

/** Applies MongoDB-style update operators to a document. */
final class Updater
{
    private const OPERATORS = ['$set', '$unset', '$inc', '$mul', '$min', '$max', '$push', '$pull', '$addToSet', '$rename', '$setOnInsert'];

    /** @param array<string,mixed> $update @return array<string,mixed> the changed document */
    public static function apply(array $doc, array $update, bool $inserting = false): array
    {
        if ($update === []) {
            throw new \InvalidArgumentException('An update needs at least one operator.');
        }
        foreach ($update as $op => $fields) {
            if (!in_array($op, self::OPERATORS, true)) {
                throw new \InvalidArgumentException("Unsupported update operator [{$op}] (updates must use operators such as \$set; use replaceOne() to replace a document).");
            }
            if (!is_array($fields)) {
                throw new \InvalidArgumentException("{$op} needs an array of fields.");
            }
            if ($op === '$setOnInsert' && !$inserting) {
                continue;
            }
            foreach ($fields as $path => $value) {
                $path = self::checkPath((string) $path);
                self::one($doc, (string) $op, $path, $value);
            }
        }
        return $doc;
    }

    private static function one(array &$doc, string $op, string $path, mixed $value): void
    {
        $current = Path::first($doc, $path);
        $exists = Path::has($doc, $path);
        switch ($op) {
            case '$set':
            case '$setOnInsert':
                Path::set($doc, $path, $value);
                break;
            case '$unset':
                Path::unset($doc, $path);
                break;
            case '$inc':
            case '$mul':
                if (!is_int($value) && !is_float($value)) {
                    throw new \InvalidArgumentException("{$op} needs a number for [{$path}].");
                }
                if ($exists && !is_int($current) && !is_float($current)) {
                    throw new \InvalidArgumentException("Cannot apply {$op} to the non-numeric field [{$path}].");
                }
                Path::set($doc, $path, $op === '$inc' ? ($current ?? 0) + $value : ($exists ? $current * $value : 0));
                break;
            case '$min':
            case '$max':
                $cmp = $exists ? Matcher::compare($value, $current) : 1;
                if (!$exists || ($cmp !== null && ($op === '$min' ? $cmp < 0 : $cmp > 0))) {
                    Path::set($doc, $path, $value);
                }
                break;
            case '$push':
            case '$addToSet':
                $list = $exists ? $current : [];
                if (!is_array($list) || !array_is_list($list)) {
                    throw new \InvalidArgumentException("Cannot apply {$op} to the non-list field [{$path}].");
                }
                $items = is_array($value) && array_key_exists('$each', $value) ? (array) $value['$each'] : [$value];
                foreach ($items as $item) {
                    if ($op === '$addToSet' && in_array($item, $list, false) && self::contains($list, $item)) {
                        continue;
                    }
                    $list[] = $item;
                }
                Path::set($doc, $path, $list);
                break;
            case '$pull':
                if ($exists && is_array($current) && array_is_list($current)) {
                    $keep = array_values(array_filter($current, static function ($el) use ($value) {
                        if (is_array($value) && $value !== [] && !array_is_list($value)) {
                            return !(is_array($el) ? Matcher::matches($el, $value) : Matcher::matches(['v' => $el], ['v' => $value]));
                        }
                        return !self::contains([$el], $value);
                    }));
                    Path::set($doc, $path, $keep);
                }
                break;
            case '$rename':
                $to = self::checkPath((string) $value);
                if ($exists) {
                    Path::set($doc, $to, $current);
                    Path::unset($doc, $path);
                }
                break;
        }
    }

    private static function contains(array $list, mixed $needle): bool
    {
        foreach ($list as $item) {
            if (json_encode($item) === json_encode($needle)) {
                return true;
            }
        }
        return false;
    }

    private static function checkPath(string $path): string
    {
        if ($path === '_id') {
            throw new \InvalidArgumentException('The _id field is immutable.');
        }
        return self::fieldName($path);
    }

    /** Field names may not start with `$` or contain NUL / empty segments: stored data can never be mistaken for operators. */
    public static function fieldName(string $path): string
    {
        foreach (explode('.', $path) as $segment) {
            if ($segment === '' || $segment[0] === '$' || str_contains($segment, "\0")) {
                throw new \InvalidArgumentException("Illegal field name [{$path}].");
            }
        }
        return $path;
    }

    /** Validate a document about to be stored: no `$`-prefixed or dotted keys at any depth. */
    public static function assertStorable(array $doc): void
    {
        foreach ($doc as $key => $value) {
            if (is_string($key) && ($key === '' || $key[0] === '$' || str_contains($key, '.') || str_contains($key, "\0"))) {
                throw new \InvalidArgumentException("Illegal field name [{$key}]: names may not start with \$, contain '.' or be empty.");
            }
            if (is_array($value)) {
                self::assertStorable($value);
            }
        }
    }

    /** @param array<string,int> $projection @return array<string,mixed> */
    public static function project(array $doc, array $projection): array
    {
        if ($projection === []) {
            return $doc;
        }
        $include = array_filter($projection, static fn ($v, $k) => $k !== '_id' && (bool) $v, ARRAY_FILTER_USE_BOTH);
        if ($include !== []) {
            $out = [];
            foreach (array_keys($include) as $path) {
                if (Path::has($doc, (string) $path)) {
                    Path::set($out, (string) $path, Path::first($doc, (string) $path));
                }
            }
            if (($projection['_id'] ?? 1) && array_key_exists('_id', $doc)) {
                $out = ['_id' => $doc['_id']] + $out;
            }
            return $out;
        }
        foreach ($projection as $path => $flag) {
            if (!$flag) {
                Path::unset($doc, (string) $path);
            }
        }
        return $doc;
    }
}
