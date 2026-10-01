<?php

declare(strict_types=1);

namespace Naluz\Queue;

use Naluz\Database\Connection;
use Naluz\Support\Collection;

final class FailedJobs
{
    public function __construct(private readonly Connection $db, private readonly string $table = 'failed_jobs')
    {
    }

    public function log(string $queue, string $payload, \Throwable $e): void
    {
        $this->db->table($this->table)->insert([
            'queue' => $queue,
            'payload' => $payload,
            'exception' => mb_substr($e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString(), 0, 8000),
            'failed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function all(): Collection
    {
        return $this->db->table($this->table)->orderBy('id')->get();
    }

    public function find(int $id): ?array
    {
        return $this->db->table($this->table)->find($id);
    }

    public function forget(int $id): bool
    {
        return $this->db->table($this->table)->where('id', $id)->delete() > 0;
    }

    public function flush(): int
    {
        return $this->db->table($this->table)->delete();
    }
}
