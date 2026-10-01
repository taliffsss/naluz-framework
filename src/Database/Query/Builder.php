<?php

declare(strict_types=1);

namespace Naluz\Database\Query;

use Naluz\Database\Connection;
use Naluz\Database\Paginator;
use Naluz\Support\Collection;

/**
 * Fluent, injection-safe query builder. Usable standalone (`$db->table('users')->...`) or as the
 * engine underneath the ORM.
 */
class Builder
{
    public string|Expression|null $from = null;
    /** @var list<string|Expression> */
    public array $columns = [];
    public bool $distinct = false;
    /** @var array{0:string,1:string}|null */
    public ?array $aggregate = null;
    public array $joins = [];
    public array $wheres = [];
    /** @var list<string|Expression> */
    public array $groups = [];
    public array $havings = [];
    public array $orders = [];
    public ?int $limit = null;
    public ?int $offset = null;
    public array $updateValues = [];
    /** Read this query from the primary even when read replicas are configured. */
    public bool $useWrite = false;

    public function __construct(public readonly Connection $connection)
    {
    }

    // ---------------------------------------------------------------- building

    public function from(string|Expression $table, ?string $as = null): static
    {
        $this->from = $as !== null && is_string($table) ? "{$table} as {$as}" : $table;
        return $this;
    }

    public function select(string|Expression ...$columns): static
    {
        $this->columns = $columns;
        return $this;
    }

    public function addSelect(string|Expression ...$columns): static
    {
        array_push($this->columns, ...$columns);
        return $this;
    }

    public function selectRaw(string $sql): static
    {
        $this->columns[] = new Expression($sql);
        return $this;
    }

    public function distinct(): static
    {
        $this->distinct = true;
        return $this;
    }

    public function where(string|Expression|\Closure|array $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
        return $this->addWhere($column, $operator, $value, $boolean, func_num_args() === 2);
    }

    public function orWhere(string|Expression|\Closure|array $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->addWhere($column, $operator, $value, 'or', func_num_args() === 2);
    }

    private function addWhere(string|Expression|\Closure|array $column, mixed $operator, mixed $value, string $boolean, bool $twoArgs): static
    {
        if (is_array($column)) {
            return $this->addWhere(function (self $q) use ($column) {
                foreach ($column as $key => $val) {
                    is_array($val) ? $q->where(...$val) : $q->where($key, '=', $val);
                }
            }, null, null, $boolean, false);
        }
        if ($column instanceof \Closure) {
            $nested = $this->newQuery();
            $column($nested);
            if ($nested->wheres !== []) {
                $this->wheres[] = ['type' => 'nested', 'query' => $nested, 'boolean' => $boolean];
            }
            return $this;
        }
        if ($twoArgs) {
            [$value, $operator] = [$operator, '='];
        }
        $operator = (string) $operator;
        if ($value === null) {
            $op = strtolower(trim($operator));
            if ($op === '=' || $op === 'is') {
                return $this->whereNull($column, $boolean);
            }
            if ($op === '!=' || $op === '<>' || $op === 'is not') {
                return $this->whereNull($column, $boolean, true);
            }
        }
        if ($value instanceof \Closure || $value instanceof self) {
            throw new \InvalidArgumentException('Use whereIn()/whereExists() for subqueries.');
        }
        $this->wheres[] = ['type' => 'basic', 'column' => $column, 'operator' => $operator, 'value' => $value, 'boolean' => $boolean];
        return $this;
    }

    public function whereColumn(string $first, string $operator, string $second, string $boolean = 'and'): static
    {
        $this->wheres[] = ['type' => 'column', 'column' => $first, 'operator' => $operator, 'second' => $second, 'boolean' => $boolean];
        return $this;
    }

    public function whereIn(string $column, array|self $values, string $boolean = 'and', bool $not = false): static
    {
        if ($values instanceof self) {
            $this->wheres[] = ['type' => 'insub', 'column' => $column, 'query' => $values, 'not' => $not, 'boolean' => $boolean];
        } else {
            if ($this->connection->driver() === 'sqlsrv' && count($values) > 2000) {
                throw new \InvalidArgumentException('SQL Server accepts at most 2100 parameters per statement: split the whereIn() list (e.g. array_chunk) or use a subquery/join.');
            }
            $this->wheres[] = ['type' => 'in', 'column' => $column, 'values' => array_values($values), 'not' => $not, 'boolean' => $boolean];
        }
        return $this;
    }

    public function whereNotIn(string $column, array|self $values, string $boolean = 'and'): static
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    public function orWhereIn(string $column, array $values): static
    {
        return $this->whereIn($column, $values, 'or');
    }

    public function whereNull(string|Expression $column, string $boolean = 'and', bool $not = false): static
    {
        $this->wheres[] = ['type' => 'null', 'column' => $column, 'not' => $not, 'boolean' => $boolean];
        return $this;
    }

    public function whereNotNull(string|Expression $column, string $boolean = 'and'): static
    {
        return $this->whereNull($column, $boolean, true);
    }

    public function orWhereNull(string $column): static
    {
        return $this->whereNull($column, 'or');
    }

    /** @param array{0:mixed,1:mixed} $range */
    public function whereBetween(string $column, array $range, string $boolean = 'and', bool $not = false): static
    {
        $this->wheres[] = ['type' => 'between', 'column' => $column, 'values' => array_values($range), 'not' => $not, 'boolean' => $boolean];
        return $this;
    }

    public function whereExists(\Closure $callback, string $boolean = 'and', bool $not = false): static
    {
        $sub = $this->newQuery();
        $callback($sub);
        $this->wheres[] = ['type' => 'exists', 'query' => $sub, 'not' => $not, 'boolean' => $boolean];
        return $this;
    }

    /** Raw condition. Pass user input ONLY through $bindings. */
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'and'): static
    {
        $this->wheres[] = ['type' => 'raw', 'sql' => $sql, 'bindings' => $bindings, 'boolean' => $boolean];
        return $this;
    }

    public function join(string $table, string $first, string $operator, string $second, string $type = 'inner'): static
    {
        $this->joins[] = compact('type', 'table', 'first', 'operator', 'second');
        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): static
    {
        return $this->join($table, $first, $operator, $second, 'left');
    }

    public function rightJoin(string $table, string $first, string $operator, string $second): static
    {
        return $this->join($table, $first, $operator, $second, 'right');
    }

    public function crossJoin(string $table): static
    {
        $this->joins[] = ['type' => 'cross', 'table' => $table];
        return $this;
    }

    public function groupBy(string|Expression ...$columns): static
    {
        array_push($this->groups, ...$columns);
        return $this;
    }

    public function having(string $column, string $operator, mixed $value, string $boolean = 'and'): static
    {
        $this->havings[] = ['type' => 'basic', 'column' => $column, 'operator' => $operator, 'value' => $value, 'boolean' => $boolean];
        return $this;
    }

    public function havingRaw(string $sql, array $bindings = [], string $boolean = 'and'): static
    {
        $this->havings[] = ['type' => 'raw', 'sql' => $sql, 'bindings' => $bindings, 'boolean' => $boolean];
        return $this;
    }

    public function orderBy(string|Expression $column, string $direction = 'asc'): static
    {
        $this->orders[] = ['column' => $column, 'direction' => $direction];
        $this->connection->grammar()->direction($direction); // fail fast
        return $this;
    }

    public function orderByDesc(string $column): static
    {
        return $this->orderBy($column, 'desc');
    }

    public function orderByRaw(string $sql): static
    {
        $this->orders[] = ['raw' => $sql];
        return $this;
    }

    public function latest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'desc');
    }

    public function oldest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'asc');
    }

    public function limit(int $value): static
    {
        $this->limit = max(0, $value);
        return $this;
    }

    public function offset(int $value): static
    {
        $this->offset = max(0, $value);
        return $this;
    }

    public function take(int $value): static
    {
        return $this->limit($value);
    }

    public function skip(int $value): static
    {
        return $this->offset($value);
    }

    public function forPage(int $page, int $perPage): static
    {
        $page = max(1, $page);
        return $this->offset(($page - 1) * $perPage)->limit($perPage);
    }

    /** Apply `$callback` only when `$condition` is truthy. */
    public function when(mixed $condition, \Closure $callback, ?\Closure $default = null): static
    {
        if ($condition) {
            $callback($this, $condition);
        } elseif ($default) {
            $default($this);
        }
        return $this;
    }

    /** Force this read onto the primary (write) connection: use it when a lagging replica would give a wrong answer. */
    public function useWritePdo(bool $value = true): static
    {
        $this->useWrite = $value;
        return $this;
    }

    public function newQuery(): static
    {
        return (new static($this->connection))->from($this->from ?? '');
    }

    // ---------------------------------------------------------------- introspection (used by the model cache)

    /** @return list<string> every table this query reads (from, joins, subqueries) */
    public function tables(): array
    {
        $tables = [];
        $add = static function (string|Expression|null $t) use (&$tables): void {
            if (is_string($t) && $t !== '') {
                $tables[] = strtolower(preg_split('/\s+as\s+/i', trim($t))[0]);
            }
        };
        $add($this->from instanceof Expression ? null : $this->from);
        foreach ($this->joins as $j) {
            $add($j['table']);
        }
        foreach ([...$this->wheres, ...$this->havings] as $w) {
            if (isset($w['query']) && $w['query'] instanceof self) {
                array_push($tables, ...$w['query']->tables());
            }
        }
        return array_values(array_unique($tables));
    }

    /**
     * The exact SQL + bindings a read (`rows`, `count`, `sum`, `min`, `max`, `avg`, `exists`, `doesntExist`, `value`,
     * `pluck`) executes. The model cache stores this so it can re-run the query to refresh a stale entry.
     *
     * @param list<mixed> $args the arguments the read method was called with
     * @return array{0:string,1:list<mixed>}
     */
    public function readPlan(string $kind, array $args = []): array
    {
        $q = clone $this;
        switch ($kind) {
            case 'count':
            case 'sum':
            case 'min':
            case 'max':
            case 'avg':
                $q->aggregate = [$kind, (string) ($args[0] ?? '*')];
                $q->orders = [];
                $q->limit = $q->offset = null;
                break;
            case 'exists':
            case 'doesntExist':
                $q->select(new Expression('1 AS one'))->limit(1);
                break;
            case 'value':
                $q->select((string) $args[0])->limit(1);
                break;
            case 'pluck':
                $q->select(...(isset($args[1]) && $args[1] !== null ? [(string) $args[0], (string) $args[1]] : [(string) $args[0]]));
                break;
            case 'rows':
                break;
            default:
                throw new \InvalidArgumentException("Unknown read kind [{$kind}].");
        }
        return [$q->toSql(), $q->getBindings()];
    }

    /** True when the query contains raw SQL, whose table dependencies cannot be known. */
    public function hasRawSql(): bool
    {
        if ($this->from instanceof Expression) {
            return true;
        }
        foreach ($this->columns as $c) {
            if ($c instanceof Expression && !str_ends_with((string) $c, 'AS one')) {
                return true;
            }
        }
        foreach ([...$this->wheres, ...$this->havings] as $w) {
            if (($w['type'] ?? '') === 'raw' || (($w['column'] ?? null) instanceof Expression)) {
                return true;
            }
            if (isset($w['query']) && $w['query'] instanceof self && $w['query']->hasRawSql()) {
                return true;
            }
        }
        foreach ($this->orders as $o) {
            if (isset($o['raw']) || ($o['column'] ?? null) instanceof Expression) {
                return true;
            }
        }
        foreach ($this->groups as $g) {
            if ($g instanceof Expression) {
                return true;
            }
        }
        return false;
    }

    // ---------------------------------------------------------------- reading

    public function toSql(): string
    {
        return $this->connection->grammar()->compileSelect($this);
    }

    /** @return list<mixed> */
    public function getBindings(): array
    {
        return [...$this->collectBindings($this->wheres), ...$this->collectBindings($this->havings)];
    }

    /** @param array<array<string,mixed>> $wheres */
    private function collectBindings(array $wheres): array
    {
        $out = [];
        foreach ($wheres as $w) {
            switch ($w['type']) {
                case 'basic':
                    $out[] = $w['value'];
                    break;
                case 'in':
                case 'between':
                    array_push($out, ...$w['values']);
                    break;
                case 'raw':
                    array_push($out, ...array_values($w['bindings']));
                    break;
                case 'nested':
                    array_push($out, ...$this->collectBindings($w['query']->wheres));
                    break;
                case 'exists':
                case 'insub':
                    array_push($out, ...$w['query']->getBindings());
                    break;
            }
        }
        return array_map(static fn ($v) => self::normalize($v), $out);
    }

    private static function normalize(mixed $v): mixed
    {
        return match (true) {
            $v instanceof \DateTimeInterface => $v->format('Y-m-d H:i:s'),
            $v instanceof \BackedEnum => $v->value,
            $v instanceof \Stringable => (string) $v,
            default => $v,
        };
    }

    /** @return Collection<int,array<string,mixed>> */
    public function get(): Collection
    {
        return new Collection($this->connection->select($this->toSql(), $this->getBindings(), $this->useWrite));
    }

    public function first(): ?array
    {
        return (clone $this)->limit(1)->get()->first();
    }

    public function find(int|string $id, string $key = 'id'): ?array
    {
        return (clone $this)->where($key, '=', $id)->first();
    }

    public function value(string $column): mixed
    {
        $row = (clone $this)->select($column)->first();
        return $row === null ? null : reset($row);
    }

    public function pluck(string $column, ?string $key = null): Collection
    {
        $rows = (clone $this)->select(...($key ? [$column, $key] : [$column]))->get();
        $alias = str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column;
        $keyAlias = $key !== null && str_contains($key, '.') ? substr($key, strrpos($key, '.') + 1) : $key;
        return $key === null
            ? $rows->pluck($alias)
            : $rows->pluck($alias, $keyAlias);
    }

    public function exists(): bool
    {
        return (clone $this)->select(new Expression('1 AS one'))->limit(1)->get()->isNotEmpty();
    }

    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    public function count(string $column = '*'): int
    {
        return (int) $this->aggregate('count', $column);
    }

    public function sum(string $column): int|float
    {
        return $this->aggregate('sum', $column) ?? 0;
    }

    public function avg(string $column): int|float|null
    {
        return $this->aggregate('avg', $column);
    }

    public function min(string $column): mixed
    {
        return $this->aggregate('min', $column);
    }

    public function max(string $column): mixed
    {
        return $this->aggregate('max', $column);
    }

    private function aggregate(string $fn, string $column): mixed
    {
        $q = clone $this;
        $q->aggregate = [$fn, $column];
        $q->orders = [];
        $q->limit = $q->offset = null;
        $row = $q->get()->first();
        $value = $row['aggregate'] ?? null;
        return is_numeric($value) ? $value + 0 : $value;
    }

    public function paginate(int $perPage = 15, int $page = 1): Paginator
    {
        $perPage = max(1, min($perPage, 1000));
        $page = max(1, $page);
        $total = (clone $this)->count();
        $items = $total > 0 ? (clone $this)->forPage($page, $perPage)->get() : new Collection();
        return new Paginator($items, $total, $perPage, $page);
    }

    /** Process large result sets in fixed-size pages. Return false from the callback to stop. */
    public function chunk(int $size, \Closure $callback): bool
    {
        $page = 1;
        do {
            $rows = (clone $this)->forPage($page, $size)->get();
            if ($rows->isEmpty()) {
                break;
            }
            if ($callback($rows, $page) === false) {
                return false;
            }
            $page++;
        } while ($rows->count() === $size);
        return true;
    }

    /** Stream rows one at a time with constant memory. */
    public function cursor(): \Generator
    {
        yield from $this->connection->cursor($this->toSql(), $this->getBindings(), $this->useWrite);
    }

    // ---------------------------------------------------------------- writing

    /** @param array<string,mixed>|list<array<string,mixed>> $values */
    public function insert(array $values, bool $ignore = false): bool
    {
        $rows = $this->rows($values);
        if ($rows === []) {
            return true;
        }
        $grammar = $this->connection->grammar();
        foreach ($this->chunkRows($rows) as $chunk) {
            $this->connection->statement($grammar->compileInsert((string) $this->from, $chunk, $ignore), $this->flatten($chunk));
        }
        return true;
    }

    public function insertGetId(array $values, string $sequence = 'id'): int|string
    {
        $grammar = $this->connection->grammar();
        $sql = $grammar->compileInsert((string) $this->from, [$values]);
        if ($this->connection->driver() === 'pgsql') {
            $row = $this->connection->selectOne($sql . ' RETURNING ' . $grammar->wrap($sequence), $this->flatten([$values]));
            return $row[$sequence];
        }
        $this->connection->statement($sql, $this->flatten([$values]));
        $id = $this->connection->lastInsertId();
        return ctype_digit($id) ? (int) $id : $id;
    }

    /**
     * Insert or update on conflict.
     *
     * @param list<array<string,mixed>> $values
     * @param list<string> $uniqueBy
     * @param list<string>|null $update columns to update (default: all non-key columns)
     */
    public function upsert(array $values, array $uniqueBy, ?array $update = null): int
    {
        $rows = $this->rows($values);
        if ($rows === []) {
            return 0;
        }
        $update ??= array_values(array_diff(array_keys($rows[0]), $uniqueBy));
        $affected = 0;
        foreach ($this->chunkRows($rows) as $chunk) {
            $sql = $this->connection->grammar()->compileUpsert((string) $this->from, $chunk, $uniqueBy, $update);
            $affected += $this->connection->affecting($sql, $this->flatten($chunk));
        }
        return $affected;
    }

    public function update(array $values): int
    {
        $this->updateValues = array_values($values);
        $columns = array_keys($values);
        $sql = $this->connection->grammar()->compileUpdate($this, $columns);
        $bindings = [
            ...array_map(static fn ($v) => self::normalize($v), array_values(array_filter($values, static fn ($v) => !$v instanceof Expression))),
            ...$this->getBindings(),
        ];
        return $this->connection->affecting($sql, $bindings);
    }

    public function increment(string $column, int|float $amount = 1, array $extra = []): int
    {
        return $this->adjust($column, '+', $amount, $extra);
    }

    public function decrement(string $column, int|float $amount = 1, array $extra = []): int
    {
        return $this->adjust($column, '-', $amount, $extra);
    }

    private function adjust(string $column, string $sign, int|float $amount, array $extra): int
    {
        if (!is_numeric($amount)) {
            throw new \InvalidArgumentException('Increment amount must be numeric.');
        }
        $wrapped = $this->connection->grammar()->wrap($column);
        return $this->update([$column => new Expression("{$wrapped} {$sign} " . (0 + $amount))] + $extra);
    }

    public function delete(): int
    {
        return $this->connection->affecting($this->connection->grammar()->compileDelete($this), $this->getBindings());
    }

    public function truncate(): void
    {
        $this->connection->statement($this->connection->grammar()->compileTruncate((string) $this->from));
    }

    /**
     * SQL Server allows at most 1000 rows per INSERT … VALUES and 2100 bound parameters per statement, so large bulk
     * writes are split into several statements there. Other drivers send one statement.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<list<array<string,mixed>>>
     */
    private function chunkRows(array $rows): array
    {
        if ($this->connection->driver() !== 'sqlsrv') {
            return [$rows];
        }
        $perRow = max(1, count($rows[0]));
        $size = max(1, min(1000, intdiv(2000, $perRow)));
        return array_chunk($rows, $size);
    }

    /** @return list<array<string,mixed>> */
    private function rows(array $values): array
    {
        if ($values === []) {
            return [];
        }
        return array_is_list($values) ? $values : [$values];
    }

    private function flatten(array $rows): array
    {
        $out = [];
        $columns = array_keys($rows[0]);
        foreach ($rows as $row) {
            foreach ($columns as $c) {
                if (!array_key_exists($c, $row)) {
                    throw new \InvalidArgumentException("Bulk insert rows must all have the same columns; missing [{$c}].");
                }
                $out[] = self::normalize($row[$c]);
            }
        }
        return $out;
    }

    public function __clone()
    {
        // nested where builders are never mutated after creation, so shallow clone is safe
    }
}
