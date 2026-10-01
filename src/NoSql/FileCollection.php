<?php

declare(strict_types=1);

namespace Naluz\NoSql;

/**
 * A collection persisted as one JSON file. Every operation takes a file lock (shared for reads, exclusive for writes)
 * and writes atomically (temp file + rename), so concurrent requests can't corrupt it or lose each other's updates.
 * It reads the whole file per operation: ideal for small/medium data, development and zero-infrastructure apps;
 * use the `mongodb` driver for large datasets.
 */
final class FileCollection extends MemoryCollection
{
    public function __construct(private readonly string $path)
    {
    }

    protected function transaction(\Closure $fn, bool $write): mixed
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the NoSQL storage directory.');
        }
        $lock = fopen($this->path . '.lock', 'c');
        if ($lock === false || !flock($lock, $write ? LOCK_EX : LOCK_SH)) {
            throw new \RuntimeException('Cannot lock the collection file.');
        }
        try {
            $state = $this->load();
            $before = $write ? json_encode($state) : null;
            $result = $fn($state);
            if ($write && json_encode($state) !== $before) {
                $this->save($state);
            }
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{docs:array<string,array<string,mixed>>,indexes:list<array>} */
    private function load(): array
    {
        if (!is_file($this->path)) {
            return ['docs' => [], 'indexes' => []];
        }
        $raw = file_get_contents($this->path);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data) || !isset($data['docs']) || !is_array($data['docs'])) {
            // never "repair" by overwriting: a damaged file must be looked at by a human
            throw new \RuntimeException('Collection file ' . basename($this->path) . ' is corrupt; refusing to continue.');
        }
        $docs = [];
        foreach ($data['docs'] as $doc) {
            $docs[self::idKey($doc['_id'])] = $doc;
        }
        return ['docs' => $docs, 'indexes' => $data['indexes'] ?? []];
    }

    /** @param array{docs:array,indexes:list<array>} $state */
    private function save(array $state): void
    {
        $json = json_encode(['version' => 1, 'indexes' => $state['indexes'], 'docs' => array_values($state['docs'])], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $tmp = $this->path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $json) === false || !rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot write the collection file.');
        }
        @chmod($this->path, 0660);
    }
}
