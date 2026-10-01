<?php

declare(strict_types=1);

namespace Naluz\Database\Query;

/**
 * Compiles builder state into SQL. Every identifier is validated and quoted; every value is a binding.
 * User-supplied column names that are not plain identifiers are rejected rather than escaped.
 */
class Grammar
{
    private const OPERATORS = [
        '=', '<', '>', '<=', '>=', '<>', '!=', 'like', 'not like', 'ilike', 'not ilike', 'is', 'is not',
    ];

    public function __construct(private readonly string $driver)
    {
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function wrap(string|Expression $value): string
    {
        if ($value instanceof Expression) {
            return (string) $value;
        }
        if ($value === '*') {
            return '*';
        }
        if (preg_match('/^(.+?)\s+as\s+(\w+)$/i', $value, $m)) {
            return $this->wrap($m[1]) . ' AS ' . $this->quote($m[2]);
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_$]*(\.([A-Za-z_][A-Za-z0-9_$]*|\*))*$/', $value)) {
            throw new \InvalidArgumentException("Illegal SQL identifier [{$value}]. Use Connection::raw() for expressions.");
        }
        return implode('.', array_map(fn (string $p) => $p === '*' ? '*' : $this->quote($p), explode('.', $value)));
    }

    public function quote(string $identifier): string
    {
        if ($this->driver === 'sqlsrv') {
            return '[' . str_replace(']', ']]', $identifier) . ']';
        }
        $q = $this->driver === 'mysql' ? '`' : '"';
        return $q . str_replace($q, $q . $q, $identifier) . $q;
    }

    /** @param list<string|Expression> $values */
    public function columnize(array $values): string
    {
        return implode(', ', array_map(fn ($v) => $this->wrap($v), $values));
    }

    public function operator(string $operator): string
    {
        $operator = strtolower(trim($operator));
        if (!in_array($operator, self::OPERATORS, true)) {
            throw new \InvalidArgumentException("Illegal SQL operator [{$operator}].");
        }
        return strtoupper($operator);
    }

    private function boolean(string $boolean): string
    {
        $boolean = strtoupper($boolean);
        if ($boolean !== 'AND' && $boolean !== 'OR') {
            throw new \InvalidArgumentException("Illegal boolean [{$boolean}].");
        }
        return $boolean;
    }

    public function direction(string $direction): string
    {
        $direction = strtoupper($direction);
        if ($direction !== 'ASC' && $direction !== 'DESC') {
            throw new \InvalidArgumentException("Illegal order direction [{$direction}].");
        }
        return $direction;
    }

    public function compileSelect(Builder $q): string
    {
        $parts = [];
        // SQL Server cannot FETCH 0 rows, so "limit 0" becomes TOP (0)
        $top = $this->driver === 'sqlsrv' && $q->limit === 0 ? 'TOP (0) ' : '';
        $parts[] = 'SELECT ' . ($q->distinct ? 'DISTINCT ' : '') . $top . $this->compileColumns($q);
        $parts[] = 'FROM ' . $this->wrap($q->from);

        foreach ($q->joins as $join) {
            $parts[] = $this->compileJoin($join);
        }
        if (($where = $this->compileWheres($q->wheres)) !== '') {
            $parts[] = 'WHERE ' . $where;
        }
        if ($q->groups) {
            $parts[] = 'GROUP BY ' . $this->columnize($q->groups);
        }
        if ($q->havings) {
            $parts[] = 'HAVING ' . $this->compileWheres($q->havings);
        }
        if ($q->orders) {
            $parts[] = 'ORDER BY ' . implode(', ', array_map(
                fn (array $o) => $o['raw'] ?? $this->wrap($o['column']) . ' ' . $this->direction($o['direction']),
                $q->orders
            ));
        } elseif ($this->driver === 'sqlsrv' && $q->limit !== 0 && ($q->limit !== null || $q->offset !== null)) {
            $parts[] = 'ORDER BY (SELECT 0)'; // OFFSET/FETCH is only valid after an ORDER BY
        }
        $parts[] = $this->compileLimit($q);
        return trim(implode(' ', array_filter($parts)));
    }

    private function compileColumns(Builder $q): string
    {
        if ($q->aggregate !== null) {
            [$fn, $column] = $q->aggregate;
            $column = $column === '*' ? '*' : $this->wrap($column);
            return strtoupper($fn) . '(' . ($q->distinct && $column !== '*' ? 'DISTINCT ' : '') . $column . ') AS '
                . ($this->driver === 'sqlsrv' ? '[aggregate]' : 'aggregate');
        }
        return $this->columnize($q->columns ?: ['*']);
    }

    private function compileJoin(array $j): string
    {
        $type = strtoupper($j['type']);
        if ($type === 'CROSS') {
            return 'CROSS JOIN ' . $this->wrap($j['table']);
        }
        return $type . ' JOIN ' . $this->wrap($j['table']) . ' ON '
            . $this->wrap($j['first']) . ' ' . $this->operator($j['operator']) . ' ' . $this->wrap($j['second']);
    }

    public function compileWheres(array $wheres): string
    {
        $sql = '';
        foreach ($wheres as $i => $w) {
            $sql .= ($i === 0 ? '' : ' ' . $this->boolean($w['boolean']) . ' ') . $this->compileWhere($w);
        }
        return $sql;
    }

    private function compileWhere(array $w): string
    {
        $col = isset($w['column']) ? $this->wrap($w['column']) : '';
        return match ($w['type']) {
            'basic' => $col . ' ' . $this->operator($w['operator']) . ' ?',
            'column' => $col . ' ' . $this->operator($w['operator']) . ' ' . $this->wrap($w['second']),
            'in' => $w['values'] === [] ? ($w['not'] ? '1 = 1' : '0 = 1')
                : $col . ($w['not'] ? ' NOT IN (' : ' IN (') . implode(', ', array_fill(0, count($w['values']), '?')) . ')',
            'null' => $col . ($w['not'] ? ' IS NOT NULL' : ' IS NULL'),
            'between' => $col . ($w['not'] ? ' NOT BETWEEN ? AND ?' : ' BETWEEN ? AND ?'),
            'raw' => $w['sql'],
            'nested' => '(' . $this->compileWheres($w['query']->wheres) . ')',
            'exists' => ($w['not'] ? 'NOT ' : '') . 'EXISTS (' . $this->compileSelect($w['query']) . ')',
            'insub' => $col . ($w['not'] ? ' NOT IN (' : ' IN (') . $this->compileSelect($w['query']) . ')',
        };
    }

    private function compileLimit(Builder $q): string
    {
        if ($this->driver === 'sqlsrv') {
            if ($q->limit === 0 || ($q->limit === null && $q->offset === null)) {
                return '';
            }
            return 'OFFSET ' . (int) ($q->offset ?? 0) . ' ROWS' . ($q->limit !== null ? ' FETCH NEXT ' . (int) $q->limit . ' ROWS ONLY' : '');
        }
        $sql = '';
        if ($q->limit !== null) {
            $sql = 'LIMIT ' . (int) $q->limit;
        } elseif ($q->offset !== null) {
            // MySQL & SQLite both require a LIMIT before OFFSET.
            $sql = $this->driver === 'pgsql' ? '' : 'LIMIT ' . ($this->driver === 'sqlite' ? '-1' : '18446744073709551615');
        }
        return $q->offset !== null ? trim($sql . ' OFFSET ' . (int) $q->offset) : $sql;
    }

    /** @param list<array<string,mixed>> $rows */
    public function compileInsert(string $table, array $rows, bool $ignore = false): string
    {
        if ($ignore && $this->driver === 'sqlsrv') {
            throw new \InvalidArgumentException('insert(…, ignore: true) is not supported on SQL Server; use upsert() or catch the duplicate-key error.');
        }
        $columns = array_keys($rows[0]);
        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $verb = $ignore && $this->driver === 'mysql' ? 'INSERT IGNORE' : 'INSERT';
        $sql = $verb . ' INTO ' . $this->wrap($table) . ' (' . $this->columnize($columns) . ') VALUES '
            . implode(', ', array_fill(0, count($rows), $placeholders));
        if ($ignore && $this->driver !== 'mysql') {
            $sql .= ' ON CONFLICT DO NOTHING';
        }
        return $sql;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $uniqueBy
     * @param list<string> $update
     */
    public function compileUpsert(string $table, array $rows, array $uniqueBy, array $update): string
    {
        if ($this->driver === 'sqlsrv') {
            return $this->compileMerge($table, $rows, $uniqueBy, $update);
        }
        $sql = $this->compileInsert($table, $rows);
        if ($this->driver === 'mysql') {
            return $sql . ' ON DUPLICATE KEY UPDATE '
                . implode(', ', array_map(fn ($c) => $this->wrap($c) . ' = VALUES(' . $this->wrap($c) . ')', $update));
        }
        return $sql . ' ON CONFLICT (' . $this->columnize($uniqueBy) . ') DO UPDATE SET '
            . implode(', ', array_map(fn ($c) => $this->wrap($c) . ' = excluded.' . $this->wrap($c), $update));
    }

    /** SQL Server has no ON CONFLICT: upsert is a MERGE (which must end with a semicolon). */
    private function compileMerge(string $table, array $rows, array $uniqueBy, array $update): string
    {
        $columns = array_keys($rows[0]);
        $values = implode(', ', array_fill(0, count($rows), '(' . implode(', ', array_fill(0, count($columns), '?')) . ')'));
        $on = implode(' AND ', array_map(fn ($c) => 'target.' . $this->wrap($c) . ' = source.' . $this->wrap($c), $uniqueBy));
        $sql = 'MERGE ' . $this->wrap($table) . ' AS target USING (VALUES ' . $values . ') AS source (' . $this->columnize($columns) . ') ON ' . $on;
        if ($update !== []) {
            $sql .= ' WHEN MATCHED THEN UPDATE SET '
                . implode(', ', array_map(fn ($c) => 'target.' . $this->wrap($c) . ' = source.' . $this->wrap($c), $update));
        }
        return $sql . ' WHEN NOT MATCHED THEN INSERT (' . $this->columnize($columns) . ') VALUES ('
            . implode(', ', array_map(fn ($c) => 'source.' . $this->wrap($c), $columns)) . ');';
    }

    /** @param list<string> $columns */
    public function compileUpdate(Builder $q, array $columns): string
    {
        $set = implode(', ', array_map(
            fn ($c, $v) => $this->wrap($c) . ' = ' . ($v instanceof Expression ? (string) $v : '?'),
            $columns,
            $q->updateValues
        ));
        $where = $this->compileWheres($q->wheres);
        return 'UPDATE ' . $this->wrap($q->from) . ' SET ' . $set . ($where !== '' ? ' WHERE ' . $where : '');
    }

    public function compileDelete(Builder $q): string
    {
        $where = $this->compileWheres($q->wheres);
        return 'DELETE FROM ' . $this->wrap($q->from) . ($where !== '' ? ' WHERE ' . $where : '');
    }

    public function compileTruncate(string $table): string
    {
        return match ($this->driver) {
            'sqlite' => 'DELETE FROM ' . $this->wrap($table),
            'pgsql' => 'TRUNCATE TABLE ' . $this->wrap($table) . ' RESTART IDENTITY',
            default => 'TRUNCATE TABLE ' . $this->wrap($table),
        };
    }
}
