<?php

declare(strict_types=1);

namespace Naluz\Console\Commands;

use Naluz\Console\Command;
use Naluz\Console\Input;
use Naluz\Console\Output;
use Naluz\Foundation\Application;
use Naluz\Queue\FailedJobs;
use Naluz\Queue\QueueManager;
use Naluz\Queue\Worker;

/** queue:work, queue:failed, queue:retry, queue:flush */
final class QueueCommands extends Command
{
    public function __construct(Application $app, private readonly string $kind)
    {
        parent::__construct($app);
    }

    public static function instances(Application $app): array
    {
        return array_map(fn ($k) => new self($app, $k), ['queue:work', 'queue:failed', 'queue:retry', 'queue:flush']);
    }

    public function name(): string
    {
        return $this->kind;
    }

    public function description(): string
    {
        return match ($this->kind) {
            'queue:work' => 'Process jobs (--queue=default --once --stop-when-empty --max-jobs=N --sleep=3 --memory=128)',
            'queue:failed' => 'List failed jobs',
            'queue:retry' => 'Re-queue a failed job: queue:retry <id|all>',
            default => 'Delete all failed jobs',
        };
    }

    public function handle(Input $input, Output $output): int
    {
        $failed = $this->app->make(FailedJobs::class);
        switch ($this->kind) {
            case 'queue:work':
                $queue = (string) $input->option('queue', 'default');
                $worker = $this->app->make(Worker::class);
                if ($input->option('once')) {
                    $result = $worker->runNextJob($queue);
                    $output->line($result === null ? 'Queue is empty.' : "Job {$result}.");
                    return 0;
                }
                $output->info("Processing queue [{$queue}] — Ctrl+C to stop");
                $n = $worker->daemon(
                    $queue,
                    (int) $input->option('sleep', '3'),
                    (int) $input->option('max-jobs', '0'),
                    (bool) $input->option('stop-when-empty'),
                    null,
                    (int) $input->option('memory', '128')
                );
                $output->line("Handled {$n} job(s).");
                return 0;

            case 'queue:failed':
                $rows = $failed->all()->map(fn ($r) => [(string) $r['id'], $r['queue'], (string) $r['failed_at'], strtok((string) $r['exception'], "\n")])->values()->all();
                $output->table(['ID', 'Queue', 'Failed at', 'Exception'], $rows);
                return 0;

            case 'queue:retry':
                $target = $input->argument(0);
                $ids = $target === 'all' ? $failed->all()->pluck('id')->all() : ($target !== null && ctype_digit($target) ? [(int) $target] : []);
                if ($ids === []) {
                    $output->error('Usage: queue:retry <id|all>');
                    return 1;
                }
                $queue = $this->app->make(QueueManager::class)->connection();
                foreach ($ids as $id) {
                    $row = $failed->find((int) $id);
                    if ($row === null) {
                        $output->error("No failed job with id {$id}.");
                        continue;
                    }
                    $queue->pushRaw($row['payload'], $row['queue']);
                    $failed->forget((int) $id);
                    $output->info("Re-queued failed job {$id}.");
                }
                return 0;

            default:
                $output->info($failed->flush() . ' failed job(s) deleted.');
                return 0;
        }
    }
}
