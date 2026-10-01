<?php

declare(strict_types=1);

namespace Naluz\Console\Commands;

use Naluz\Console\Command;
use Naluz\Console\Input;
use Naluz\Console\Output;
use Naluz\Foundation\Application;
use Naluz\Schedule\Schedule;

final class ScheduleCommands extends Command
{
    public function __construct(Application $app, private readonly string $kind)
    {
        parent::__construct($app);
    }

    public static function instances(Application $app): array
    {
        return [new self($app, 'schedule:run'), new self($app, 'schedule:list')];
    }

    public function name(): string
    {
        return $this->kind;
    }

    public function description(): string
    {
        return $this->kind === 'schedule:run' ? 'Run the tasks that are due now (call this from cron every minute)' : 'List scheduled tasks and their next run';
    }

    public function handle(Input $input, Output $output): int
    {
        $schedule = $this->app->make(Schedule::class);
        $now = new \DateTimeImmutable();
        if ($this->kind === 'schedule:list') {
            $output->table(['Expression', 'Task', 'Next run'], array_map(
                fn ($e) => [$e->expression(), $e->description(), $e->nextRun($now)->format('Y-m-d H:i')],
                $schedule->events()
            ));
            return 0;
        }
        $results = $schedule->run($now);
        if ($results === []) {
            $output->line('No scheduled tasks are due.');
        }
        $failed = 0;
        foreach ($results as $task => $status) {
            $status === 'failed' ? $output->error("[failed]  {$task}") : $output->info("[{$status}]  {$task}");
            $failed += $status === 'failed' ? 1 : 0;
        }
        return $failed > 0 ? 1 : 0;
    }
}
