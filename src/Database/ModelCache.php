<?php

declare(strict_types=1);

namespace Naluz\Database;

use Naluz\Security\DecryptException;
use Naluz\Security\Encrypter;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Transparent query-result cache for the ORM (enabled with MODEL_CACHING=true).
 *
 * Freshness:
 *  - Entries live for `ttl` seconds (default 300 = 5 minutes) — the safety net for changes this application cannot see.
 *  - Entries are keyed by connection + SQL + bindings + a random *version token per table involved* (and a global token).
 *    Any write to a table replaces its token, so every cached query that read it becomes unreachable at once. Writes are
 *    detected at the connection, so Model::save(), query-builder updates, pivot attach/detach, raw statements and
 *    migrations all invalidate.
 *  - Re-caching: queries that were recently cached are remembered; when a write commits they are re-run and stored
 *    again under the new versions, so the first reader after a change hits a warm cache (no stampede on the database).
 *  - DELETE / TRUNCATE / DDL flush the whole model cache (foreign-key cascades modify tables a statement never names);
 *    statements that matched no rows invalidate nothing.
 *  - Reads inside a transaction bypass the cache; writes in a transaction invalidate again after COMMIT.
 *  - Queries with raw SQL are never cached (their table dependencies are unknown).
 *  - The token is read BEFORE the query runs, so a concurrent write can never be masked by a stale store.
 *  - If the cache store fails (Redis down) the query simply runs against the database (`fallback`).
 */
final class ModelCache
{
    public const DEFAULT_TTL = 300;

    public int $hits = 0;
    public int $misses = 0;
    public int $recached = 0;

    /** @var list<string> */
    private array $exclude;
    private bool $suspended = false;
    private bool $recaching = false;
    /** @var (\Closure(string):Connection)|null */
    private ?\Closure $connections = null;

    /**
     * @param int $ttl seconds an entry may live (default 300 = 5 minutes)
     * @param list<string> $excludeTables tables that are never cached
     * @param 'all'|'table' $flushOnDelete
     * @param bool $recache re-run recently cached queries after a write commits
     * @param int $recacheLimit how many distinct recent queries are remembered / re-run (bounds the cost of a write)
     * @param int $recacheDebounce seconds during which a burst of writes triggers re-caching only once
     * @param bool $readFromPrimary with read replicas, cache misses and re-caching read the primary, so a lagging replica
     *                              can never put stale rows into the cache for the next 5 minutes
     * @param bool $fallback keep working (straight from the database) when the cache store throws
     */
    public function __construct(
        private readonly CacheInterface $store,
        private readonly int $ttl = self::DEFAULT_TTL,
        private readonly string $prefix = 'naluz_mc_',
        array $excludeTables = [],
        private readonly string $flushOnDelete = 'all',
        private readonly ?Encrypter $encrypter = null,
        private readonly int $versionTtl = 2_592_000,
        private readonly bool $recache = false,
        private readonly int $recacheLimit = 20,
        private readonly int $recacheDebounce = 2,
        private readonly bool $fallback = true,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $readFromPrimary = true,
    ) {
        $this->exclude = array_map('strtolower', $excludeTables);
    }

    public function defaultTtl(): int
    {
        return $this->ttl;
    }

    public function readsFromPrimary(): bool
    {
        return $this->readFromPrimary;
    }

    /** Needed for re-caching: how to get the connection a remembered query belongs to. */
    public function resolveConnectionsWith(\Closure $resolver): void
    {
        $this->connections = $resolver;
    }

    /** @param list<string> $tables */
    public function canCache(array $tables): bool
    {
        return !$this->suspended && $tables !== [] && array_intersect($tables, $this->exclude) === [];
    }

    /** Run a block with the cache switched off for reads (writes still invalidate). */
    public function runWithout(\Closure $callback): mixed
    {
        $previous = $this->suspended;
        $this->suspended = true;
        try {
            return $callback();
        } finally {
            $this->suspended = $previous;
        }
    }

    /**
     * @template T
     * @param list<string> $tables every table the query reads
     * @param \Closure():T $load runs the real query
     * @param array{kind:string,sql:string,bindings:list<mixed>,args:list<mixed>}|null $plan lets writes re-run this query
     * @return T
     */
    public function remember(string $connection, array $tables, string $fingerprint, ?int $ttl, \Closure $load, ?array $plan = null): mixed
    {
        try {
            // 1. versions first (see class docs), 2. lookup, 3. load + store under the *earlier* versions
            $versions = $this->versions($connection, $tables);
            $key = $this->key($connection, $versions, $fingerprint);
            $stored = $this->store->get($key);
            $value = $stored === null ? null : $this->decode($stored);
        } catch (\Throwable $e) {
            return $this->degrade($e, $load);
        }
        if ($value !== null) {
            $this->hits++;
            return $value['v'];
        }

        $this->misses++;
        $result = $load();
        try {
            $this->store->set($key, $this->encode(['v' => $result]), max(1, $ttl ?? $this->ttl));
            if ($this->recache && $plan !== null) {
                $this->track($connection, $tables, $fingerprint, $ttl, $plan);
            }
        } catch (\Throwable $e) {
            $this->degrade($e, static fn () => null);
        }
        return $result;
    }

    /**
     * React to a write statement: find the tables it touches, invalidate, and (once committed) re-cache.
     *
     * @param bool $committed false for statements inside an open transaction
     */
    public function handleWrite(string $connection, string $sql, bool $committed = true, ?int $affected = null): void
    {
        try {
            $this->applyWrite($connection, $sql, $committed, $affected);
        } catch (\Throwable $e) {
            // a broken cache must never break a write; entries then age out through the TTL
            $this->degrade($e, static fn () => null);
        }
    }

    private function applyWrite(string $connection, string $sql, bool $committed, ?int $affected): void
    {
        $sql = ltrim($sql);
        $keyword = strtoupper(strtok($sql, " \t\n\r(") ?: '');
        $isDdl = in_array($keyword, ['DROP', 'ALTER', 'CREATE', 'RENAME'], true);
        $wipes = $isDdl || in_array($keyword, ['DELETE', 'TRUNCATE'], true);

        if ($wipes && $this->flushOnDelete === 'all') {
            $this->flushAll();
            $changed = null;
        } elseif (($table = $this->tableOf($sql)) === null) {
            $this->flushAll(); // unknown statement shape: the only safe answer is "everything"
            $changed = null;
        } else {
            $this->bump($connection, $table);
            $changed = [$table];
            if ($isDdl || $keyword === 'TRUNCATE') {
                $this->flushAll();
                $changed = null;
            }
        }

        if ($isDdl) {
            $this->store->delete($this->prefix . 'hot'); // schema changed: remembered queries may no longer be valid
            return;
        }
        if ($committed && $this->recache) {
            $this->refresh($connection, $changed);
        }
    }

    public function flushTable(string $connection, string $table): void
    {
        $this->bump($connection, strtolower($table));
    }

    public function flushAll(): void
    {
        $this->store->set($this->prefix . 'ver_all', bin2hex(random_bytes(8)), $this->versionTtl);
    }

    /** Remove every entry (not just invalidate). Useful for the local driver to reclaim disk. */
    public function purge(): bool
    {
        return $this->store->clear();
    }

    // ------------------------------------------------------------------ re-caching

    /** Remember this query (most recent first, bounded) so a later write can refresh it. */
    private function track(string $connection, array $tables, string $fingerprint, ?int $ttl, array $plan): void
    {
        $id = hash('sha256', $connection . '|' . $fingerprint);
        $hot = $this->registry();
        unset($hot[$id]);
        $hot = [$id => [
            'conn' => $connection, 'tables' => $tables, 'fp' => $fingerprint, 'ttl' => $ttl, 'plan' => $plan,
        ]] + $hot;
        $this->store->set($this->prefix . 'hot', $this->encode(['v' => array_slice($hot, 0, max(1, $this->recacheLimit), true)]), $this->versionTtl);
    }

    /** @return array<string,array<string,mixed>> */
    private function registry(): array
    {
        $stored = $this->store->get($this->prefix . 'hot');
        $decoded = $stored === null ? null : $this->decode($stored);
        return is_array($decoded['v'] ?? null) ? $decoded['v'] : [];
    }

    /**
     * Re-run remembered queries affected by a change and store fresh results under the new versions.
     *
     * @param list<string>|null $changed tables that changed (null = everything)
     */
    private function refresh(string $connection, ?array $changed): void
    {
        if ($this->recaching || $this->connections === null) {
            return;
        }
        // a burst of writes triggers one re-cache, not one per write
        $lock = $this->prefix . 'recache_lock';
        if ($this->store->get($lock) !== null) {
            return;
        }
        $hot = $this->registry();
        if ($hot === []) {
            return;
        }
        $this->store->set($lock, 1, max(1, $this->recacheDebounce));

        $this->recaching = true;
        $dead = [];
        try {
            foreach ($hot as $id => $entry) {
                if ($entry['conn'] !== $connection || ($changed !== null && array_intersect($entry['tables'], $changed) === [])) {
                    continue;
                }
                try {
                    $this->warm($entry);
                    $this->recached++;
                } catch (\Throwable) {
                    $dead[] = $id; // e.g. a table was dropped: forget the query, never fail the write
                }
            }
            if ($dead !== []) {
                $this->store->set($this->prefix . 'hot', $this->encode(['v' => array_diff_key($hot, array_flip($dead))]), $this->versionTtl);
            }
        } finally {
            $this->recaching = false;
        }
    }

    /** @param array<string,mixed> $entry */
    private function warm(array $entry): void
    {
        $connection = ($this->connections)($entry['conn']);
        ['kind' => $kind, 'sql' => $sql, 'bindings' => $bindings, 'args' => $args] = $entry['plan'];
        $rows = $connection->select($sql, $bindings, $this->readFromPrimary);
        $value = self::shape($kind, $rows, $args);

        $versions = $this->versions($entry['conn'], $entry['tables']);
        $this->store->set(
            $this->key($entry['conn'], $versions, $entry['fp']),
            $this->encode(['v' => $value]),
            max(1, $entry['ttl'] ?? $this->ttl)
        );
    }

    /** Turn raw rows into what the ORM would have cached for this kind of read (mirrors Query\Builder). */
    private static function shape(string $kind, array $rows, array $args): mixed
    {
        switch ($kind) {
            case 'rows':
                return $rows;
            case 'count':
                return (int) self::aggregate($rows);
            case 'sum':
                return self::aggregate($rows) ?? 0;
            case 'min':
            case 'max':
            case 'avg':
                return self::aggregate($rows);
            case 'exists':
                return $rows !== [];
            case 'doesntExist':
                return $rows === [];
            case 'value':
                return $rows === [] ? null : reset($rows[0]);
            case 'pluck':
                $alias = str_contains((string) $args[0], '.') ? substr((string) $args[0], (int) strrpos((string) $args[0], '.') + 1) : (string) $args[0];
                $key = $args[1] ?? null;
                $keyAlias = $key !== null && str_contains((string) $key, '.') ? substr((string) $key, (int) strrpos((string) $key, '.') + 1) : $key;
                return $key === null ? array_column($rows, $alias) : array_column($rows, $alias, $keyAlias);
        }
        throw new \InvalidArgumentException("Unknown read kind [{$kind}].");
    }

    private static function aggregate(array $rows): mixed
    {
        $v = $rows[0]['aggregate'] ?? null;
        return is_numeric($v) ? $v + 0 : $v;
    }

    // ------------------------------------------------------------------ internals

    private function key(string $connection, string $versions, string $fingerprint): string
    {
        return $this->prefix . 'q_' . hash('sha256', $connection . '|' . $versions . '|' . $fingerprint);
    }

    /** @param list<string> $tables */
    private function versions(string $connection, array $tables): string
    {
        $keys = [$this->prefix . 'ver_all'];
        foreach ($tables as $t) {
            $keys[] = $this->prefix . 'ver_' . $connection . '_' . $t;
        }
        $found = (array) $this->store->getMultiple($keys);
        $out = [];
        foreach ($keys as $k) {
            $token = $found[$k] ?? null;
            if (!is_string($token) || $token === '') {
                $token = bin2hex(random_bytes(8));
                $this->store->set($k, $token, $this->versionTtl);
            }
            $out[] = $token;
        }
        return implode('.', $out);
    }

    private function bump(string $connection, string $table): void
    {
        $this->store->set($this->prefix . 'ver_' . $connection . '_' . $table, bin2hex(random_bytes(8)), $this->versionTtl);
    }

    private function tableOf(string $sql): ?string
    {
        $id = '[`"\[]?([A-Za-z_][A-Za-z0-9_$]*)[`"\]]?';
        foreach (
            [
            "/^INSERT\\s+(?:OR\\s+\\w+\\s+|IGNORE\\s+)?INTO\\s+{$id}/i",
            "/^REPLACE\\s+INTO\\s+{$id}/i",
            "/^MERGE\\s+(?:INTO\\s+)?{$id}/i",
            "/^UPDATE\\s+(?:OR\\s+\\w+\\s+)?{$id}/i",
            "/^DELETE\\s+FROM\\s+{$id}/i",
            "/^TRUNCATE\\s+(?:TABLE\\s+)?{$id}/i",
            "/^(?:DROP|ALTER)\\s+TABLE\\s+(?:IF\\s+EXISTS\\s+)?{$id}/i",
            "/^CREATE\\s+(?:UNIQUE\\s+)?INDEX\\s+.+?\\s+ON\\s+{$id}/i",
            "/^CREATE\\s+TABLE\\s+(?:IF\\s+NOT\\s+EXISTS\\s+)?{$id}/i",
            ] as $pattern
        ) {
            if (preg_match($pattern, $sql, $m)) {
                return strtolower($m[1]);
            }
        }
        return null;
    }

    private function encode(array $payload): mixed
    {
        return $this->encrypter === null ? $payload : $this->encrypter->encrypt($payload);
    }

    /** @return array{v:mixed}|null */
    private function decode(mixed $stored): ?array
    {
        if ($this->encrypter !== null) {
            try {
                $stored = is_string($stored) ? $this->encrypter->decrypt($stored) : null;
            } catch (DecryptException | \JsonException) {
                return null; // tampered or from an old key: treat as a miss
            }
        }
        return is_array($stored) && array_key_exists('v', $stored) ? $stored : null;
    }

    /**
     * The cache store failed. With `fallback` on, report it and carry on without the cache; otherwise rethrow.
     *
     * @template T
     * @param \Closure():T $then
     * @return T
     */
    private function degrade(\Throwable $e, \Closure $then): mixed
    {
        if (!$this->fallback) {
            throw $e;
        }
        $message = 'Model cache unavailable, continuing without it: ' . $e::class . ': ' . $e->getMessage();
        $this->logger !== null ? $this->logger->warning($message) : error_log('[naluz] ' . $message);
        return $then();
    }
}
