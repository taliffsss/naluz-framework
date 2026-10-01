<?php

declare(strict_types=1);

namespace Naluz\Database;

use Naluz\Database\Query\Builder;
use Naluz\Database\Query\Grammar;
use Naluz\Database\Query\Expression;
use Naluz\Database\Schema\Schema;

/**
 * Thin PDO wrapper: always prepared statements, lazy connect, nested transactions
 * (savepoints), query listeners, and optional READ / WRITE splitting.
 *
 * With read replicas configured there are two independent PDO sessions: the write connection (primary) and a read
 * connection (a replica, chosen from a pool with failover). They never share state, a read connection is opened
 * read-only, and a read is routed to the PRIMARY whenever a replica could give a wrong answer:
 *   - inside a transaction (you must see your own uncommitted writes),
 *   - after this process has written, while `sticky` is on (read-your-writes despite replication lag),
 *   - for statements that are really writes (`INSERT … RETURNING`) or take locks (`FOR UPDATE`),
 *   - when asked explicitly: `useWritePdo()` on a query, or `usingWritePdo(fn)` around a block.
 */
class Connection
{
    private ?\PDO $pdo = null;
    private ?\Closure $factory = null;
    private int $transactions = 0;
    private ?\PDO $readPdo = null;
    /** @var list<\Closure():\PDO> one factory per read replica */
    private array $readFactories = [];
    private bool $recordsModified = false;
    private int $forceWrite = 0;
    private int $readFailedAt = 0;
    private const READ_RETRY_SECONDS = 30;
    /** @var list<callable(string,bool,?int):void> */
    private array $writeListeners = [];
    /** @var list<string> write statements issued inside the current transaction */
    private array $pendingWrites = [];
    /** @var list<array{0:string,1:int}> writes executed but not yet announced to listeners */
    private array $queuedWrites = [];
    /** @var list<callable(string,array,float):void> */
    private array $listeners = [];
    private readonly Grammar $grammar;

    /**
     * @param \PDO|\Closure():\PDO $pdo the write connection
     * @param \PDO|\Closure():\PDO|list<\Closure():\PDO>|null $read read replica(s); null = no splitting
     * @param bool $sticky after a write, send reads to the primary for the rest of this process (read-your-writes)
     * @param bool $readFallback if every replica is unreachable, read from the primary instead of failing
     * @param 'random'|'ordered' $readStrategy spread reads randomly over replicas, or try them in the listed order
     */
    public function __construct(
        \PDO|\Closure $pdo,
        private readonly string $driver,
        private readonly string $name = 'default',
        \PDO|\Closure|array|null $read = null,
        private readonly bool $sticky = true,
        private readonly bool $readFallback = true,
        private readonly string $readStrategy = 'random',
    ) {
        if ($pdo instanceof \PDO) {
            $this->pdo = $pdo;
        } else {
            $this->factory = $pdo;
        }
        if ($read instanceof \PDO) {
            $this->readPdo = $read;
        } elseif ($read instanceof \Closure) {
            $this->readFactories = [$read];
        } elseif (is_array($read)) {
            $this->readFactories = array_values($read);
        }
        $this->grammar = new Grammar($driver);
    }

    /** True when reads and writes use different connections. */
    public function hasReadConnection(): bool
    {
        return $this->readPdo !== null || $this->readFactories !== [];
    }

    /**
     * The PDO a read would use right now (a replica, or the primary when splitting is off / a replica is down).
     * Replicas are tried in random or listed order; unreachable ones are skipped.
     */
    public function readPdo(): \PDO
    {
        if ($this->readPdo !== null && $this->readPdo !== $this->pdo) {
            return $this->readPdo;
        }
        if ($this->readFactories === []) {
            return $this->pdo();
        }
        // After a total replica outage we run on the primary, but try the replicas again after a short pause
        if ($this->readPdo === $this->pdo && $this->readFailedAt !== 0 && time() - $this->readFailedAt < self::READ_RETRY_SECONDS) {
            return $this->pdo();
        }
        $order = array_keys($this->readFactories);
        if ($this->readStrategy === 'random') {
            for ($i = count($order) - 1; $i > 0; $i--) {
                $j = random_int(0, $i);
                [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
            }
        }
        $last = null;
        foreach ($order as $i) {
            try {
                $this->readFailedAt = 0;
                return $this->readPdo = ($this->readFactories[$i])();
            } catch (\PDOException $e) {
                $last = $e;
            }
        }
        if (!$this->readFallback) {
            throw new QueryException('(connecting to a read replica)', [], $last);
        }
        error_log('[naluz] all read replicas of connection [' . $this->name . '] are unreachable; reading from the primary: ' . $last?->getMessage());
        $this->readFailedAt = time();
        return $this->readPdo = $this->pdo();
    }

    /** Run a block with every read going to the primary (migrations, queue polling, uniqueness checks…). */
    public function usingWritePdo(\Closure $callback): mixed
    {
        $this->forceWrite++;
        try {
            return $callback($this);
        } finally {
            $this->forceWrite--;
        }
    }

    /** Does this statement have to run on the primary even though it looks like a read? */
    public static function requiresWrite(string $sql): bool
    {
        return self::isWrite($sql)
            || preg_match('/\bFOR\s+(UPDATE|SHARE|NO\s+KEY\s+UPDATE|KEY\s+SHARE)\b|\bLOCK\s+IN\s+SHARE\s+MODE\b|\bWITH\s*\(\s*(UPDLOCK|HOLDLOCK|XLOCK|ROWLOCK)/i', $sql) === 1;
    }

    private function readsUsePrimary(string $sql, bool $force): bool
    {
        return !$this->hasReadConnection()
            || $force
            || $this->forceWrite > 0
            || $this->transactions > 0
            || ($this->sticky && $this->recordsModified)
            || self::requiresWrite($sql);
    }

    public function pdo(): \PDO
    {
        if ($this->pdo === null) {
            $this->pdo = ($this->factory)();
        }
        return $this->pdo;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    /** MySQL implicitly commits DDL, so wrapping migrations in a transaction only helps elsewhere. */
    public function transactionalDdl(): bool
    {
        return $this->driver !== 'mysql';
    }

    public function name(): string
    {
        return $this->name;
    }

    public function grammar(): Grammar
    {
        return $this->grammar;
    }

    public function table(string|Expression $table, ?string $as = null): Builder
    {
        return (new Builder($this))->from($table, $as);
    }

    public function query(): Builder
    {
        return new Builder($this);
    }

    public function schema(): Schema
    {
        return new Schema($this);
    }

    public static function raw(string $sql): Expression
    {
        return new Expression($sql);
    }

    /**
     * @param bool $useWritePdo force this read onto the primary
     * @return list<array<string,mixed>>
     */
    public function select(string $sql, array $bindings = [], bool $useWritePdo = false): array
    {
        return $this->run($sql, $bindings, static fn (\PDOStatement $s) => $s->fetchAll(\PDO::FETCH_ASSOC), $useWritePdo, true);
    }

    /** @return \Generator<int,array<string,mixed>> */
    public function cursor(string $sql, array $bindings = [], bool $useWritePdo = false): \Generator
    {
        $stmt = $this->prepare($sql, $bindings, $this->readsUsePrimary($sql, $useWritePdo) ? $this->pdo() : $this->readPdo());
        try {
            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                yield $row;
            }
        } finally {
            $stmt->closeCursor();
        }
    }

    public function selectOne(string $sql, array $bindings = [], bool $useWritePdo = false): ?array
    {
        return $this->select($sql, $bindings, $useWritePdo)[0] ?? null;
    }

    /** Execute INSERT/UPDATE/DELETE and return affected rows. */
    public function affecting(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings, static fn (\PDOStatement $s) => $s->rowCount());
    }

    /** Execute any statement (DDL etc.). */
    public function statement(string $sql, array $bindings = []): bool
    {
        return $this->run($sql, $bindings, static fn () => true);
    }

    public function lastInsertId(?string $sequence = null): string
    {
        return (string) $this->pdo()->lastInsertId($sequence);
    }

    private function prepare(string $sql, array $bindings, ?\PDO $pdo = null): \PDOStatement
    {
        $start = microtime(true);
        try {
            $stmt = ($pdo ?? $this->pdo())->prepare($sql);
            foreach (array_values($bindings) as $i => $value) {
                $stmt->bindValue($i + 1, $value, match (true) {
                    is_int($value) => \PDO::PARAM_INT,
                    is_bool($value) => \PDO::PARAM_BOOL,
                    $value === null => \PDO::PARAM_NULL,
                    default => \PDO::PARAM_STR,
                });
            }
            $stmt->execute();
        } catch (\PDOException $e) {
            throw new QueryException($sql, $bindings, $e);
        }
        foreach ($this->listeners as $listener) {
            $listener($sql, $bindings, (microtime(true) - $start) * 1000);
        }
        if (self::isWrite($sql)) {
            $this->recordsModified = true; // from now on (sticky) reads must see the primary
        }
        if ($this->writeListeners !== [] && self::isWrite($sql)) {
            // announced after the statement has been fully consumed (see run()), so listeners may run queries safely
            $this->queuedWrites[] = [$sql, $stmt->rowCount()];
        }
        return $stmt;
    }

    /**
     * @template T
     * @param \Closure(\PDOStatement):T $fetch
     * @return T
     */
    private function run(string $sql, array $bindings, \Closure $fetch, bool $useWritePdo = false, bool $isRead = false): mixed
    {
        $pdo = $isRead && !$this->readsUsePrimary($sql, $useWritePdo) ? $this->readPdo() : $this->pdo();
        $stmt = $this->prepare($sql, $bindings, $pdo);
        try {
            return $fetch($stmt);
        } finally {
            $stmt->closeCursor();
            $this->dispatchWrites();
        }
    }

    /**
     * Be told about every statement that changes data or schema (INSERT/UPDATE/DELETE/DDL…), including ones issued by
     * `Connection::select()` such as PostgreSQL's `INSERT … RETURNING`.
     *
     * The listener receives `(sql, committed, affectedRows)`:
     *  - statements that matched no rows (`affectedRows === 0` for INSERT/UPDATE/DELETE) are not announced at all;
     *  - inside a transaction `committed` is false; after the outermost COMMIT every such statement is announced again
     *    with `committed === true` (and `affectedRows === null`), so caches invalidated early cannot be re-filled with
     *    pre-commit data, and re-warming can wait for the data to really exist.
     *
     * @param callable(string,bool,?int):void $listener
     */
    public function onWrite(callable $listener): void
    {
        $this->writeListeners[] = $listener;
    }

    private function notifyWrite(string $sql, bool $committed, ?int $affected): void
    {
        foreach ($this->writeListeners as $listener) {
            $listener($sql, $committed, $affected);
        }
    }

    private function dispatchWrites(): void
    {
        while ($this->queuedWrites !== []) {
            [$sql, $affected] = array_shift($this->queuedWrites);
            if ($affected === 0 && preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql)) {
                continue; // nothing changed, nothing to invalidate
            }
            if ($this->transactions > 0) {
                $this->pendingWrites[] = $sql;
                $this->notifyWrite($sql, false, $affected);
            } else {
                $this->notifyWrite($sql, true, $affected);
            }
        }
    }

    private static function isWrite(string $sql): bool
    {
        $keyword = strtoupper(strtok(ltrim($sql), " \t\n\r(") ?: '');
        return !in_array($keyword, ['SELECT', 'WITH', 'PRAGMA', 'SAVEPOINT', 'RELEASE', 'ROLLBACK', 'BEGIN', 'COMMIT', 'SET', 'SHOW', 'EXPLAIN', 'DESCRIBE', 'USE'], true)
            || ($keyword === 'WITH' && preg_match('/\b(INSERT|UPDATE|DELETE)\b/i', $sql) === 1);
    }

    public function listen(callable $listener): void
    {
        $this->listeners[] = $listener;
    }

    /**
     * @template T
     * @param \Closure(self):T $callback
     * @return T
     */
    public function transaction(\Closure $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
        } catch (\Throwable $e) {
            $this->rollBack();
            throw $e;
        }
        $this->commit();
        return $result;
    }

    public function beginTransaction(): void
    {
        if ($this->transactions === 0) {
            $this->pdo()->beginTransaction();
        } else {
            $this->pdo()->exec(($this->driver === 'sqlsrv' ? 'SAVE TRANSACTION naluz_' : 'SAVEPOINT naluz_') . $this->transactions);
        }
        $this->transactions++;
    }

    public function commit(): void
    {
        $this->transactions--;
        if ($this->transactions === 0) {
            $this->pdo()->commit();
            $pending = array_unique($this->pendingWrites);
            $this->pendingWrites = [];
            foreach ($pending as $sql) {
                $this->notifyWrite($sql, true, null);
            }
        } else {
            if ($this->driver !== 'sqlsrv') { // SQL Server has no RELEASE: a savepoint simply ends with its transaction
                $this->pdo()->exec('RELEASE SAVEPOINT naluz_' . $this->transactions);
            }
        }
    }

    public function rollBack(): void
    {
        $this->transactions--;
        if ($this->transactions === 0) {
            $this->pdo()->rollBack();
            $this->pendingWrites = [];
        } else {
            $this->pdo()->exec(($this->driver === 'sqlsrv' ? 'ROLLBACK TRANSACTION naluz_' : 'ROLLBACK TO SAVEPOINT naluz_') . $this->transactions);
        }
    }

    public function transactionLevel(): int
    {
        return $this->transactions;
    }
}
