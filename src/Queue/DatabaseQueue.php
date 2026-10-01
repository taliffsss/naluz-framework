<?php

declare(strict_types=1);

namespace Naluz\Queue;

use Naluz\Database\Connection;

/**
 * Queue stored in the `jobs` table. Reservation is a compare-and-swap UPDATE, so two workers can never take the
 * same job on any supported database. Jobs whose worker died become available again after `$retryAfter` seconds.
 */
final class DatabaseQueue implements Queue
{
    public function __construct(
        private readonly Connection $db,
        private readonly Payload $payloads,
        private readonly int $retryAfter = 90,
        private readonly string $table = 'jobs',
    ) {
    }

    public function push(Job $job): void
    {
        $this->pushRaw($this->payloads->encode($job), $job->queueName(), $job->delaySeconds());
    }

    public function pushRaw(string $payload, string $queue = 'default', int $delay = 0): void
    {
        $now = time();
        $this->db->table($this->table)->insert([
            'queue' => $queue,
            'payload' => $payload,
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $now + max(0, $delay),
            'created_at' => $now,
        ]);
    }

    public function pop(string $queue = 'default'): ?QueuedJob
    {
        $now = time();
        for ($i = 0; $i < 5; $i++) { // lost a race → try the next candidate
            $row = $this->db->table($this->table)
                ->useWritePdo() // never poll a lagging replica: it could hand the same job to two workers
                ->where('queue', $queue)
                ->where('available_at', '<=', $now)
                ->where(fn ($q) => $q->whereNull('reserved_at')->orWhere('reserved_at', '<=', $now - $this->retryAfter))
                ->orderBy('id')
                ->first();
            if ($row === null) {
                return null;
            }
            $claimed = $this->db->table($this->table)
                ->where('id', $row['id'])
                ->where(fn ($q) => $q->whereNull('reserved_at')->orWhere('reserved_at', '<=', $now - $this->retryAfter))
                ->update(['reserved_at' => $now, 'attempts' => (int) $row['attempts'] + 1]);
            if ($claimed === 1) {
                $id = (int) $row['id'];
                return new QueuedJob(
                    $id,
                    $queue,
                    (string) $row['payload'],
                    (int) $row['attempts'] + 1,
                    fn () => $this->db->table($this->table)->where('id', $id)->delete(),
                    fn (int $delay) => $this->db->table($this->table)->where('id', $id)
                        ->update(['reserved_at' => null, 'available_at' => time() + max(0, $delay)])
                );
            }
        }
        return null;
    }

    public function size(string $queue = 'default'): int
    {
        return $this->db->table($this->table)->useWritePdo()->where('queue', $queue)->count();
    }
}
