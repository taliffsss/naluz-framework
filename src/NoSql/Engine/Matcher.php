<?php

declare(strict_types=1);

namespace Naluz\NoSql\Engine;

/**
 * Evaluates MongoDB-style filters against a document (used by the memory and file drivers).
 *
 * Unknown operators throw instead of being ignored: a query that silently matches everything because of a typo, or
 * because an attacker slipped in an operator the engine does not understand, would be a security hole.
 */
final class Matcher
{
    public static function matches(array $doc, array $filter): bool
    {
        foreach ($filter as $key => $condition) {
            $key = (string) $key;
            if ($key[0] === '$') {
                if (!self::logical($doc, $key, $condition)) {
                    return false;
                }
                continue;
            }
            if (!self::field($doc, $key, $condition)) {
                return false;
            }
        }
        return true;
    }

    private static function logical(array $doc, string $op, mixed $conditions): bool
    {
        if (!in_array($op, ['$and', '$or', '$nor'], true)) {
            throw new \InvalidArgumentException("Unsupported top-level operator [{$op}].");
        }
        if (!is_array($conditions) || !array_is_list($conditions) || $conditions === []) {
            throw new \InvalidArgumentException("{$op} needs a non-empty list of filters.");
        }
        $results = array_map(static fn ($f) => is_array($f) ? self::matches($doc, $f) : throw new \InvalidArgumentException("{$op} entries must be filters."), $conditions);
        return match ($op) {
            '$and' => !in_array(false, $results, true),
            '$or' => in_array(true, $results, true),
            '$nor' => !in_array(true, $results, true),
        };
    }

    private static function field(array $doc, string $path, mixed $condition): bool
    {
        if (self::isOperatorObject($condition)) {
            foreach ($condition as $op => $arg) {
                if ($op === '$options') {
                    continue; // consumed by $regex
                }
                if (!self::operator($doc, $path, (string) $op, $arg, $condition)) {
                    return false;
                }
            }
            return true;
        }
        return self::equals($doc, $path, $condition);
    }

    private static function isOperatorObject(mixed $v): bool
    {
        if (!is_array($v) || $v === [] || array_is_list($v)) {
            return false;
        }
        $dollar = array_filter(array_keys($v), static fn ($k) => is_string($k) && str_starts_with($k, '$'));
        if ($dollar !== [] && count($dollar) !== count($v)) {
            throw new \InvalidArgumentException('Cannot mix operators and plain fields in one condition.');
        }
        return $dollar !== [];
    }

    private static function operator(array $doc, string $path, string $op, mixed $arg, array $all): bool
    {
        $values = Path::values($doc, $path, $exists);
        $candidates = self::candidates($values);

        switch ($op) {
            case '$eq':
                return self::equals($doc, $path, $arg);
            case '$ne':
                return !self::equals($doc, $path, $arg);
            case '$gt':
            case '$gte':
            case '$lt':
            case '$lte':
                foreach ($candidates as $c) {
                    $cmp = self::compare($c, $arg);
                    if (
                        $cmp !== null && match ($op) {
                        '$gt' => $cmp > 0, '$gte' => $cmp >= 0, '$lt' => $cmp < 0, '$lte' => $cmp <= 0,
                        }
                    ) {
                        return true;
                    }
                }
                return false;
            case '$in':
            case '$nin':
                if (!is_array($arg) || !array_is_list($arg)) {
                    throw new \InvalidArgumentException("{$op} needs a list.");
                }
                $hit = false;
                foreach ($arg as $candidate) {
                    if (self::equals($doc, $path, $candidate)) {
                        $hit = true;
                        break;
                    }
                }
                return $op === '$in' ? $hit : !$hit;
            case '$exists':
                return (bool) $exists === (bool) $arg;
            case '$size':
                return array_reduce($values, static fn ($c, $v) => $c || (is_array($v) && array_is_list($v) && count($v) === (int) $arg), false);
            case '$all':
                if (!is_array($arg)) {
                    throw new \InvalidArgumentException('$all needs a list.');
                }
                foreach ($arg as $needed) {
                    if (!self::equals($doc, $path, $needed)) {
                        return false;
                    }
                }
                return true;
            case '$elemMatch':
                foreach ($values as $v) {
                    foreach (is_array($v) && array_is_list($v) ? $v : [] as $element) {
                        if (is_array($element) ? self::matches($element, $arg) : self::field(['v' => $element], 'v', $arg)) {
                            return true;
                        }
                    }
                }
                return false;
            case '$regex':
                return self::regex($candidates, (string) $arg, (string) ($all['$options'] ?? ''));
            case '$not':
                return !self::field($doc, $path, $arg);
        }
        throw new \InvalidArgumentException("Unsupported query operator [{$op}].");
    }

    /** A field holding a list also matches on its elements (MongoDB semantics). @return list<mixed> */
    private static function candidates(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            $out[] = $v;
            if (is_array($v) && array_is_list($v)) {
                array_push($out, ...$v);
            }
        }
        return $out;
    }

    private static function equals(array $doc, string $path, mixed $expected): bool
    {
        $values = Path::values($doc, $path, $exists);
        if ($expected === null) {
            return !$exists || in_array(null, $values, true);
        }
        foreach (self::candidates($values) as $c) {
            if (self::same($c, $expected)) {
                return true;
            }
        }
        return false;
    }

    /** Type-strict equality (so "1" does not match 1, and false does not match 0), numbers compare by value. */
    private static function same(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a == $b;
        }
        if (is_array($a) && is_array($b)) {
            return json_encode($a) === json_encode($b);
        }
        return $a === $b;
    }

    /** @return int|null null when the two values are of types that cannot be ordered against each other */
    public static function compare(mixed $a, mixed $b): ?int
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a <=> $b;
        }
        if (is_string($a) && is_string($b)) {
            return strcmp($a, $b) <=> 0;
        }
        if (is_bool($a) && is_bool($b)) {
            return (int) $a <=> (int) $b;
        }
        return null;
    }

    /** @param list<mixed> $candidates */
    private static function regex(array $candidates, string $pattern, string $options): bool
    {
        if (strlen($pattern) > 1000) {
            throw new \InvalidArgumentException('Regular expression is too long.');
        }
        $flags = '';
        foreach (str_split($options) as $o) {
            if (!in_array($o, ['i', 'm', 's', 'x'], true)) {
                throw new \InvalidArgumentException("Unsupported regex option [{$o}].");
            }
            $flags .= $o;
        }
        $regex = '~' . str_replace('~', '\~', $pattern) . '~u' . $flags;
        foreach ($candidates as $c) {
            if (is_string($c) && @preg_match($regex, $c) === 1) { // an engine error (backtrack limit) counts as "no match"
                return true;
            }
        }
        return false;
    }

    /** Sort documents by `['field' => 1|-1, …]` (null/missing first, then numbers, strings, booleans). */
    public static function sort(array $docs, array $sort): array
    {
        $rank = static fn ($v) => match (true) {
            $v === null => 0, is_int($v) || is_float($v) => 1, is_string($v) => 2, is_bool($v) => 3, default => 4,
        };
        usort($docs, static function (array $x, array $y) use ($sort, $rank): int {
            foreach ($sort as $field => $dir) {
                $a = Path::first($x, (string) $field);
                $b = Path::first($y, (string) $field);
                $c = $rank($a) <=> $rank($b) ?: (self::compare($a, $b) ?? 0);
                if ($c !== 0) {
                    return $dir < 0 ? -$c : $c;
                }
            }
            return 0;
        });
        return $docs;
    }
}
