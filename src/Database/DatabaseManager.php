<?php

declare(strict_types=1);

namespace Naluz\Database;

use Naluz\Database\Query\Builder;

/** Builds and caches named connections from `config/database.php`. */
final class DatabaseManager
{
    /** @var array<string,Connection> */
    private array $connections = [];
    /** @var list<\Closure(string,string,bool,?int):void> */
    private array $writeListeners = [];

    /** @param array{default:string,connections:array<string,array<string,mixed>>} $config */
    public function __construct(private readonly array $config)
    {
    }

    public function connection(?string $name = null): Connection
    {
        $name ??= $this->config['default'];
        if (!isset($this->connections[$name])) {
            $this->connections[$name] = $connection = $this->make($name);
            $this->attach($name, $connection);
        }
        return $this->connections[$name];
    }

    public function extend(string $name, Connection $connection): void
    {
        $this->connections[$name] = $connection;
        $this->attach($name, $connection);
    }

    /** Listen for data/schema changes on every connection, existing and future: `fn (string $connection, string $sql, bool $committed, ?int $affected)`. */
    public function listenForWrites(\Closure $listener): void
    {
        $this->writeListeners[] = $listener;
        foreach ($this->connections as $name => $connection) {
            $connection->onWrite(fn (string $sql, bool $committed, ?int $affected) => $listener($name, $sql, $committed, $affected));
        }
    }

    private function attach(string $name, Connection $connection): void
    {
        foreach ($this->writeListeners as $listener) {
            $connection->onWrite(fn (string $sql, bool $committed, ?int $affected) => $listener($name, $sql, $committed, $affected));
        }
    }

    public function table(string $table, ?string $as = null): Builder
    {
        return $this->connection()->table($table, $as);
    }

    private function make(string $name): Connection
    {
        $config = $this->config['connections'][$name]
            ?? throw new \InvalidArgumentException("Database connection [{$name}] is not configured.");
        $driver = $config['driver'] ?? throw new \InvalidArgumentException("Connection [{$name}] has no driver.");
        $strategy = (string) ($config['read_strategy'] ?? 'random');
        if (!in_array($strategy, ['random', 'ordered'], true)) {
            throw new \InvalidArgumentException("Connection [{$name}]: read_strategy must be random or ordered.");
        }

        ['write' => $write, 'reads' => $reads] = self::expand($config);
        $readOnly = ($config['read_only'] ?? true) !== false;

        return new Connection(
            $this->pdoFactory($driver, $write, false),
            $driver,
            $name,
            $reads === [] ? null : array_map(fn (array $c) => $this->pdoFactory($driver, $c, $readOnly), $reads),
            (bool) ($config['sticky'] ?? true),
            (bool) ($config['read_fallback'] ?? true),
            $strategy
        );
    }

    /**
     * Split a connection config into the single write (primary) config and the list of read (replica) configs.
     *
     * ```php
     * 'mysql' => [
     *     'driver' => 'mysql', 'database' => 'app', 'username' => 'app', 'password' => '…',
     *     'write' => ['host' => '10.0.0.1'],
     *     'read'  => ['host' => ['10.0.0.2', '10.0.0.3'], 'password' => 'read-only-user-password'],
     *     // or one array per replica: 'read' => [['host' => '10.0.0.2'], ['host' => '10.0.0.3', 'port' => 3307]],
     * ],
     * ```
     * Keys that a section does not set are inherited from the connection itself.
     *
     * @return array{write:array<string,mixed>,reads:list<array<string,mixed>>}
     */
    public static function expand(array $config): array
    {
        $base = array_diff_key($config, ['read' => 1, 'write' => 1]);
        $write = array_replace($base, (array) ($config['write'] ?? []));
        foreach (['host', 'database'] as $key) {
            if (isset($write[$key]) && is_array($write[$key])) {
                $write[$key] = $write[$key][array_key_first($write[$key])] ?? null; // one primary
            }
        }

        $sections = (array) ($config['read'] ?? []);
        if ($sections !== [] && !(array_is_list($sections) && is_array($sections[0] ?? null))) {
            $sections = [$sections];
        }
        $reads = [];
        foreach ($sections as $section) {
            $variants = [array_replace($base, (array) $section)];
            foreach (['host', 'database'] as $key) {
                $next = [];
                foreach ($variants as $variant) {
                    $values = $variant[$key] ?? null;
                    if (is_array($values)) {
                        foreach ($values as $v) {
                            $next[] = [$key => $v] + $variant;
                        }
                    } else {
                        $next[] = $variant;
                    }
                }
                $variants = $next;
            }
            array_push($reads, ...$variants);
        }
        return ['write' => $write, 'reads' => $reads];
    }

    /** @return \Closure():\PDO */
    private function pdoFactory(string $driver, array $config, bool $readOnly): \Closure
    {
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];
        // real server-side prepares: no client-side string interpolation (the sqlsrv driver does not allow the attribute)
        if ($driver !== 'sqlsrv') {
            $options += [\PDO::ATTR_EMULATE_PREPARES => false, \PDO::ATTR_STRINGIFY_FETCHES => false];
        }

        return match ($driver) {
            'sqlite' => function () use ($config, $options, $readOnly): \PDO {
                $pdo = new \PDO('sqlite:' . ($config['database'] ?? ':memory:'), null, null, $options);
                $pdo->exec('PRAGMA foreign_keys = ON');
                if ($readOnly) {
                    $pdo->exec('PRAGMA query_only = ON');
                }
                return $pdo;
            },
            'mysql' => function () use ($config, $options, $readOnly): \PDO {
                $pdo = new \PDO(
                    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $config['host'] ?? '127.0.0.1', $config['port'] ?? 3306, $config['database'] ?? '', $config['charset'] ?? 'utf8mb4'),
                    $config['username'] ?? null,
                    $config['password'] ?? null,
                    $options + [\PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode='STRICT_ALL_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO'"]
                );
                if ($readOnly) {
                    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
                }
                return $pdo;
            },
            'pgsql' => function () use ($config, $options, $readOnly): \PDO {
                $pdo = new \PDO(
                    sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'] ?? '127.0.0.1', $config['port'] ?? 5432, $config['database'] ?? ''),
                    $config['username'] ?? null,
                    $config['password'] ?? null,
                    $options
                );
                if ($readOnly) {
                    $pdo->exec('SET default_transaction_read_only = on');
                }
                return $pdo;
            },
            'sqlsrv' => fn () => new \PDO(
                self::sqlsrvDsn($config, $readOnly),
                $config['username'] ?? null,
                $config['password'] ?? null,
                $options + (defined('PDO::SQLSRV_ATTR_ENCODING') ? [\PDO::SQLSRV_ATTR_ENCODING => \PDO::SQLSRV_ENCODING_UTF8] : [])
            ),
            default => throw new \InvalidArgumentException("Unsupported database driver [{$driver}]."),
        };
    }

    /** `sqlsrv:Server=host,1433;Database=db;Encrypt=yes;TrustServerCertificate=no[;ApplicationIntent=ReadOnly]` */
    public static function sqlsrvDsn(array $config, bool $readOnly = false): string
    {
        foreach (['host', 'database'] as $k) {
            $v = (string) ($config[$k] ?? '');
            if ($k === 'host' && $v === '' || preg_match('/[;{}]/', $v)) {
                throw new \InvalidArgumentException("Invalid SQL Server {$k} in connection config.");
            }
        }
        $dsn = sprintf('sqlsrv:Server=%s,%d;Database=%s', $config['host'], (int) ($config['port'] ?? 1433), $config['database'] ?? '');
        $dsn .= ';Encrypt=' . (($config['encrypt'] ?? true) ? 'yes' : 'no');
        $dsn .= ';TrustServerCertificate=' . (($config['trust_server_certificate'] ?? false) ? 'yes' : 'no');
        if ($readOnly) {
            $dsn .= ';ApplicationIntent=ReadOnly'; // lets an Availability Group route the session to a readable secondary
        }
        return $dsn;
    }
}
