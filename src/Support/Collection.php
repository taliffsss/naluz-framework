<?php

declare(strict_types=1);

namespace Naluz\Support;

/**
 * Fluent wrapper around arrays. Returned by ORM queries and `collect()`.
 *
 * @template TKey of array-key
 * @template TValue
 * @implements \ArrayAccess<TKey,TValue>
 * @implements \IteratorAggregate<TKey,TValue>
 */
class Collection implements \ArrayAccess, \IteratorAggregate, \Countable, \JsonSerializable
{
    /** @param array<TKey,TValue> $items */
    public function __construct(protected array $items = [])
    {
    }

    public static function make(iterable $items = []): static
    {
        return new static($items instanceof \Traversable ? iterator_to_array($items) : $items);
    }

    public function all(): array
    {
        return $this->items;
    }

    public function toArray(): array
    {
        return array_map(
            static fn ($v) => is_object($v) && method_exists($v, 'toArray') ? $v->toArray() : $v,
            $this->items
        );
    }

    public function map(callable $fn): static
    {
        $keys = array_keys($this->items);
        return new static(array_combine($keys, array_map($fn, $this->items, $keys)));
    }

    public function filter(?callable $fn = null): static
    {
        return new static($fn ? array_filter($this->items, $fn, ARRAY_FILTER_USE_BOTH) : array_filter($this->items));
    }

    public function each(callable $fn): static
    {
        foreach ($this->items as $k => $v) {
            if ($fn($v, $k) === false) {
                break;
            }
        }
        return $this;
    }

    public function values(): static
    {
        return new static(array_values($this->items));
    }

    public function keys(): static
    {
        return new static(array_keys($this->items));
    }

    public function first(?callable $fn = null, mixed $default = null): mixed
    {
        foreach ($this->items as $k => $v) {
            if ($fn === null || $fn($v, $k)) {
                return $v;
            }
        }
        return $default;
    }

    public function last(mixed $default = null): mixed
    {
        return $this->items === [] ? $default : $this->items[array_key_last($this->items)];
    }

    public function pluck(string $key, ?string $indexBy = null): static
    {
        $out = [];
        foreach ($this->items as $item) {
            $value = self::extract($item, $key);
            if ($indexBy !== null) {
                $out[self::extract($item, $indexBy)] = $value;
            } else {
                $out[] = $value;
            }
        }
        return new static($out);
    }

    public function keyBy(string $key): static
    {
        $out = [];
        foreach ($this->items as $item) {
            $out[self::extract($item, $key)] = $item;
        }
        return new static($out);
    }

    public function groupBy(string $key): static
    {
        $out = [];
        foreach ($this->items as $item) {
            $out[self::extract($item, $key)][] = $item;
        }
        return new static(array_map(static fn ($g) => new static($g), $out));
    }

    public function sortBy(string|callable $by, bool $descending = false): static
    {
        $items = $this->items;
        $get = is_callable($by) ? $by : static fn ($i) => self::extract($i, $by);
        uasort($items, static fn ($a, $b) => $descending ? $get($b) <=> $get($a) : $get($a) <=> $get($b));
        return new static($items);
    }

    public function unique(): static
    {
        return new static(array_unique($this->items, SORT_REGULAR));
    }

    public function sum(string|callable|null $by = null): int|float
    {
        $get = match (true) {
            $by === null => static fn ($i) => $i,
            is_callable($by) => $by,
            default => static fn ($i) => self::extract($i, $by),
        };
        return array_sum(array_map($get, $this->items));
    }

    public function contains(mixed $value): bool
    {
        return in_array($value, $this->items, true);
    }

    public function push(mixed ...$values): static
    {
        foreach ($values as $v) {
            $this->items[] = $v;
        }
        return $this;
    }

    public function merge(iterable $other): static
    {
        return new static(array_merge($this->items, $other instanceof self ? $other->all() : (array) $other));
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function isNotEmpty(): bool
    {
        return $this->items !== [];
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $offset === null ? $this->items[] = $value : $this->items[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    protected static function extract(mixed $item, string $key): mixed
    {
        if (is_array($item)) {
            return Arr::get($item, $key);
        }
        return is_object($item) ? ($item->{$key} ?? null) : null;
    }
}
