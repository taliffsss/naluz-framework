<?php

declare(strict_types=1);

namespace Naluz\NoSql;

use Naluz\Database\Paginator;
use Naluz\NoSql\Engine\Updater;
use Naluz\Support\Collection;

/**
 * Injection-safe query builder for document collections.
 *
 *   $users->query()->where('age', '>=', 18)->where('status', 'active')->orderBy('name')->limit(10)->get();
 *   $users->query()->where('role', 'admin')->orWhere('score', '>', 90)->count();
 *   $users->query()->where('email', $input)->first();   // $input = ['$ne' => null] is matched as a literal value, not an operator
 *
 * Every value is wrapped in an explicit `$eq` / `$in` / … so request input can never become a query operator (NoSQL
 * injection), and field names are validated (`$`-prefixed names are rejected).
 */
final class Query
{
    /** @var list<array{0:string,1:array<string,mixed>}> */
    private array $conditions = [];
    /** @var array<string,int> */
    private array $sort = [];
    private ?int $limit = null;
    private int $skip = 0;
    /** @var array<string,int> */
    private array $projection = [];

    public function __construct(private readonly DocumentCollection $collection)
    {
    }

    // ---------------------------------------------------------------- conditions

    public function where(string $field, mixed $operator = null, mixed $value = null): self
    {
        return $this->add('and', func_num_args() === 2 ? [$field, '=', $operator] : [$field, (string) $operator, $value]);
    }

    public function orWhere(string $field, mixed $operator = null, mixed $value = null): self
    {
        return $this->add('or', func_num_args() === 2 ? [$field, '=', $operator] : [$field, (string) $operator, $value]);
    }

    public function whereIn(string $field, array $values): self
    {
        return $this->add('and', [$field, 'in', $values]);
    }

    public function whereNotIn(string $field, array $values): self
    {
        return $this->add('and', [$field, 'not in', $values]);
    }

    public function whereNull(string $field): self
    {
        return $this->add('and', [$field, '=', null]);
    }

    public function whereNotNull(string $field): self
    {
        return $this->add('and', [$field, '!=', null]);
    }

    public function whereExists(string $field, bool $exists = true): self
    {
        $this->conditions[] = ['and', [self::field($field) => ['$exists' => $exists]]];
        return $this;
    }

    /** @param array{0:mixed,1:mixed} $range */
    public function whereBetween(string $field, array $range): self
    {
        $this->conditions[] = ['and', [self::field($field) => ['$gte' => self::literal($range[0]), '$lte' => self::literal($range[1])]]];
        return $this;
    }

    /** @param array{0:string,1:string,2:mixed} $args */
    private function add(string $boolean, array $args): self
    {
        [$field, $op, $value] = $args;
        $field = self::field($field);
        $op = strtolower(trim($op));
        $value = self::literal($value);

        $cond = match ($op) {
            '=', '==', 'contains' => [$field => ['$eq' => $value]],
            '!=', '<>' => [$field => ['$ne' => $value]],
            '>' => [$field => ['$gt' => $value]],
            '>=' => [$field => ['$gte' => $value]],
            '<' => [$field => ['$lt' => $value]],
            '<=' => [$field => ['$lte' => $value]],
            'in' => [$field => ['$in' => array_values((array) $value)]],
            'not in' => [$field => ['$nin' => array_values((array) $value)]],
            'like', 'ilike' => [$field => ['$regex' => self::likeToRegex((string) $value), '$options' => $op === 'ilike' ? 'i' : '']],
            default => throw new \InvalidArgumentException("Unsupported operator [{$op}]."),
        };
        $this->conditions[] = [$boolean, $cond];
        return $this;
    }

    /** SQL-style LIKE: `%` any run, `_` one character; everything else is matched literally. */
    private static function likeToRegex(string $pattern): string
    {
        $out = '';
        foreach (str_split($pattern) as $ch) {
            $out .= match ($ch) {
                '%' => '.*', '_' => '.', default => preg_quote($ch, '~')
            };
        }
        return '^' . $out . '$';
    }

    private static function field(string $field): string
    {
        return Updater::fieldName($field);
    }

    private static function literal(mixed $v): mixed
    {
        return match (true) {
            $v instanceof \DateTimeInterface => $v->format(DATE_ATOM),
            $v instanceof \BackedEnum => $v->value,
            $v instanceof \Stringable => (string) $v,
            is_object($v) => throw new \InvalidArgumentException('Unsupported value of type ' . get_debug_type($v) . '.'),
            is_array($v) => array_map([self::class, 'literal'], $v),
            default => $v,
        };
    }

    // ---------------------------------------------------------------- shaping

    public function orderBy(string $field, string $direction = 'asc'): self
    {
        $dir = strtolower($direction);
        if ($dir !== 'asc' && $dir !== 'desc') {
            throw new \InvalidArgumentException('Direction must be asc or desc.');
        }
        $this->sort[self::field($field)] = $dir === 'asc' ? 1 : -1;
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = max(0, $limit);
        return $this;
    }

    public function skip(int $skip): self
    {
        $this->skip = max(0, $skip);
        return $this;
    }

    public function select(string ...$fields): self
    {
        foreach ($fields as $f) {
            $this->projection[self::field($f)] = 1;
        }
        return $this;
    }

    // ---------------------------------------------------------------- the compiled filter

    /** @return array<string,mixed> */
    public function toFilter(): array
    {
        if ($this->conditions === []) {
            return [];
        }
        $groups = [[]];
        foreach ($this->conditions as [$boolean, $cond]) {
            if ($boolean === 'or' && $groups[array_key_last($groups)] !== []) {
                $groups[] = [];
            }
            $groups[array_key_last($groups)][] = $cond;
        }
        $wrap = static fn (array $g) => count($g) === 1 ? $g[0] : ['$and' => $g];
        return count($groups) === 1 ? $wrap($groups[0]) : ['$or' => array_map($wrap, $groups)];
    }

    /** @return array<string,mixed> */
    private function options(): array
    {
        $o = [];
        if ($this->sort !== []) {
            $o['sort'] = $this->sort;
        }
        if ($this->limit !== null) {
            $o['limit'] = $this->limit;
        }
        if ($this->skip > 0) {
            $o['skip'] = $this->skip;
        }
        if ($this->projection !== []) {
            $o['projection'] = $this->projection;
        }
        return $o;
    }

    // ---------------------------------------------------------------- reading

    /** @return Collection<int,array<string,mixed>> */
    public function get(): Collection
    {
        return new Collection($this->limit === 0 ? [] : $this->collection->find($this->toFilter(), $this->options()));
    }

    public function first(): ?array
    {
        return (clone $this)->limit(1)->get()->first();
    }

    public function count(): int
    {
        return $this->collection->count($this->toFilter());
    }

    public function exists(): bool
    {
        return $this->first() !== null;
    }

    public function pluck(string $field): Collection
    {
        return $this->get()->pluck($field);
    }

    public function paginate(int $perPage = 15, int $page = 1): Paginator
    {
        $perPage = max(1, min($perPage, 1000));
        $page = max(1, $page);
        $total = $this->count();
        $items = $total > 0 ? (clone $this)->skip(($page - 1) * $perPage)->limit($perPage)->get() : new Collection();
        return new Paginator($items, $total, $perPage, $page);
    }

    // ---------------------------------------------------------------- writing

    /** @param array<string,mixed> $document */
    public function insert(array $document): string|int
    {
        return $this->collection->insertOne($document);
    }

    /** `$set` the given fields on every matching document. @param array<string,mixed> $fields */
    public function update(array $fields): UpdateResult
    {
        foreach (array_keys($fields) as $f) {
            self::field((string) $f);
        }
        return $this->collection->updateMany($this->toFilter(), ['$set' => array_map([self::class, 'literal'], $fields)]);
    }

    public function increment(string $field, int|float $by = 1): UpdateResult
    {
        return $this->collection->updateMany($this->toFilter(), ['$inc' => [self::field($field) => $by]]);
    }

    public function unsetFields(string ...$fields): UpdateResult
    {
        return $this->collection->updateMany($this->toFilter(), ['$unset' => array_fill_keys(array_map([self::class, 'field'], $fields), '')]);
    }

    public function delete(): int
    {
        return $this->collection->deleteMany($this->toFilter());
    }
}
