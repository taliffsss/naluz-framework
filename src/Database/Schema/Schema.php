<?php

declare(strict_types=1);

namespace Naluz\Database\Schema;

use Naluz\Database\Connection;

/** DDL builder for MySQL, PostgreSQL and SQLite. */
final class Schema
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function create(string $table, \Closure $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);
        $g = $this->db->grammar();

        $lines = array_map(fn (ColumnDefinition $c) => $this->columnSql($c), $blueprint->columns);
        foreach ($blueprint->columns as $c) {
            if ($c->foreign) {
                $lines[] = sprintf(
                    'FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s',
                    $g->wrap($c->name),
                    $g->wrap($c->foreign['table']),
                    $g->wrap($c->foreign['column']),
                    $this->action($c->foreign['onDelete'])
                );
            }
        }
        $this->db->statement(sprintf(
            'CREATE TABLE %s (%s)%s',
            $g->wrap($table),
            implode(', ', $lines),
            $this->db->driver() === 'mysql' ? ' DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' : ''
        ));
        $this->indexes($blueprint);
    }

    public function table(string $table, \Closure $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);
        $g = $this->db->grammar();
        foreach ($blueprint->columns as $c) {
            // SQL Server: "ADD <column>" (no COLUMN keyword)
            $this->db->statement('ALTER TABLE ' . $g->wrap($table) . ($this->db->driver() === 'sqlsrv' ? ' ADD ' : ' ADD COLUMN ') . $this->columnSql($c));
        }
        foreach ($blueprint->dropColumns as $col) {
            $this->db->statement('ALTER TABLE ' . $g->wrap($table) . ' DROP COLUMN ' . $g->wrap($col));
        }
        $this->indexes($blueprint);
    }

    public function drop(string $table): void
    {
        $this->db->statement('DROP TABLE ' . $this->db->grammar()->wrap($table));
    }

    public function dropIfExists(string $table): void
    {
        $this->db->statement('DROP TABLE IF EXISTS ' . $this->db->grammar()->wrap($table));
    }

    /** Drop every table in the current database (used by `migrate:fresh`). */
    public function dropAllTables(): void
    {
        $g = $this->db->grammar();
        switch ($this->db->driver()) {
            case 'sqlite':
                $tables = array_column($this->db->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"), 'name');
                $this->db->statement('PRAGMA foreign_keys = OFF');
                foreach ($tables as $t) {
                    $this->db->statement('DROP TABLE IF EXISTS ' . $g->wrap($t));
                }
                $this->db->statement('PRAGMA foreign_keys = ON');
                break;
            case 'sqlsrv':
                // foreign keys first, otherwise dependent tables cannot be dropped
                foreach ($this->db->select("SELECT 'ALTER TABLE ' + QUOTENAME(OBJECT_SCHEMA_NAME(parent_object_id)) + '.' + QUOTENAME(OBJECT_NAME(parent_object_id)) + ' DROP CONSTRAINT ' + QUOTENAME(name) AS stmt FROM sys.foreign_keys", [], true) as $row) {
                    $this->db->statement($row['stmt']);
                }
                $tables = array_column($this->db->select("SELECT TABLE_NAME AS name FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_SCHEMA = SCHEMA_NAME()", [], true), 'name');
                foreach ($tables as $t) {
                    $this->db->statement('DROP TABLE IF EXISTS ' . $g->wrap($t));
                }
                break;
            case 'pgsql':
                $tables = array_column($this->db->select('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = ?', ['BASE TABLE']), 'name');
                foreach ($tables as $t) {
                    $this->db->statement('DROP TABLE IF EXISTS ' . $g->wrap($t) . ' CASCADE');
                }
                break;
            default:
                $tables = array_column($this->db->select('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = database() AND table_type = ?', ['BASE TABLE']), 'name');
                $this->db->statement('SET FOREIGN_KEY_CHECKS = 0');
                foreach ($tables as $t) {
                    $this->db->statement('DROP TABLE IF EXISTS ' . $g->wrap($t));
                }
                $this->db->statement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    public function hasTable(string $table): bool
    {
        return match ($this->db->driver()) {
            'sqlite' => $this->db->selectOne("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$table], true) !== null,
            'sqlsrv' => $this->db->selectOne('SELECT 1 AS present FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = SCHEMA_NAME() AND TABLE_NAME = ?', [$table], true) !== null,
            'pgsql' => $this->db->selectOne('SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?', [$table], true) !== null,
            default => $this->db->selectOne('SELECT 1 FROM information_schema.tables WHERE table_schema = database() AND table_name = ?', [$table], true) !== null,
        };
    }

    private function indexes(Blueprint $b): void
    {
        $g = $this->db->grammar();
        $all = $b->indexes;
        foreach ($b->columns as $c) {
            if ($c->unique) {
                $all[] = ['columns' => [$c->name], 'unique' => true, 'name' => null];
            } elseif ($c->index || $c->foreign) {
                $all[] = ['columns' => [$c->name], 'unique' => false, 'name' => null];
            }
        }
        foreach ($all as $idx) {
            $name = $idx['name'] ?? $b->table . '_' . implode('_', $idx['columns']) . ($idx['unique'] ? '_unique' : '_index');
            $this->db->statement(sprintf(
                'CREATE %sINDEX %s ON %s (%s)',
                $idx['unique'] ? 'UNIQUE ' : '',
                $g->quote($name),
                $g->wrap($b->table),
                $g->columnize($idx['columns'])
            ));
        }
    }

    private function action(string $action): string
    {
        $action = strtoupper($action);
        if (!in_array($action, ['CASCADE', 'RESTRICT', 'SET NULL', 'NO ACTION'], true)) {
            throw new \InvalidArgumentException("Illegal foreign key action [{$action}].");
        }
        return $action;
    }

    private function columnSql(ColumnDefinition $c): string
    {
        $driver = $this->db->driver();
        $name = $this->db->grammar()->wrap($c->name);

        if ($c->type === 'id') {
            return $name . ' ' . match ($driver) {
                'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                'pgsql' => 'BIGSERIAL PRIMARY KEY',
                'sqlsrv' => 'BIGINT IDENTITY(1,1) PRIMARY KEY',
                default => 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            };
        }

        $type = match ($c->type) {
            // SQL Server: NVARCHAR (Unicode); lengths above 4000 need MAX
            'string' => $driver === 'sqlsrv'
                ? 'NVARCHAR(' . ((int) $c->params['length'] > 4000 ? 'MAX' : (int) $c->params['length']) . ')'
                : 'VARCHAR(' . (int) $c->params['length'] . ')',
            'text' => $driver === 'sqlsrv' ? 'NVARCHAR(MAX)' : 'TEXT',
            'integer' => $driver === 'sqlsrv' ? 'INT' : 'INTEGER',
            'bigInteger' => $driver === 'sqlite' ? 'INTEGER' : 'BIGINT',
            'boolean' => match ($driver) {
                'mysql' => 'TINYINT(1)',
                'sqlite' => 'INTEGER',
                'sqlsrv' => 'BIT',
                default => 'BOOLEAN',
            },
            'decimal' => sprintf('DECIMAL(%d, %d)', $c->params['precision'], $c->params['scale']),
            'float' => match ($driver) {
                'pgsql' => 'DOUBLE PRECISION',
                'sqlite' => 'REAL',
                'sqlsrv' => 'FLOAT',
                default => 'DOUBLE',
            },
            'json' => match ($driver) {
                'pgsql' => 'JSONB',
                'sqlite' => 'TEXT',
                'sqlsrv' => 'NVARCHAR(MAX)',
                default => 'JSON',
            },
            'date' => 'DATE',
            'timestamp' => match ($driver) {
                'pgsql' => 'TIMESTAMP(0) WITHOUT TIME ZONE',
                'mysql' => 'TIMESTAMP NULL',
                'sqlsrv' => 'DATETIME2(0)',
                default => 'DATETIME',
            },
            default => throw new \InvalidArgumentException("Unknown column type [{$c->type}]."),
        };
        if ($c->unsigned && $driver === 'mysql' && in_array($c->type, ['integer', 'bigInteger'], true)) {
            $type .= ' UNSIGNED';
        }

        $sql = $name . ' ' . $type . ($c->nullable ? ' NULL' : ' NOT NULL');
        if ($c->hasDefault) {
            $sql .= ' DEFAULT ' . match (true) {
                $c->default === null => 'NULL',
                is_bool($c->default) => $c->default ? '1' : '0',
                is_int($c->default), is_float($c->default) => (string) $c->default,
                default => $this->literal((string) $c->default),
            };
        } elseif ($c->useCurrent) {
            $sql .= ' DEFAULT CURRENT_TIMESTAMP';
        }
        return $sql;
    }

    /** A string default as a SQL literal. MySQL treats backslashes specially, so it asks the driver; the others double quotes. */
    private function literal(string $value): string
    {
        return match ($this->db->driver()) {
            'mysql' => $this->db->pdo()->quote($value),
            'sqlsrv' => "N'" . str_replace("'", "''", $value) . "'",
            default => "'" . str_replace("'", "''", $value) . "'",
        };
    }
}
