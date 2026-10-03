<?php

declare(strict_types=1);

namespace Naluz\Console;

use Naluz\Console\Commands;
use Naluz\Foundation\Application;

/** The `naluz` command line. */
final class Kernel
{
    /** @var array<string,Command> */
    private array $commands = [];

    public function __construct(private readonly Application $app, private readonly Output $output = new Output())
    {
        foreach (
            [
            Commands\RunServerCommand::class, Commands\KeyGenerateCommand::class, Commands\MigrateCommand::class,
            Commands\MigrateRollbackCommand::class, Commands\MigrateStatusCommand::class, Commands\RouteListCommand::class,
            Commands\MakeCommand::class, Commands\DbCommands::class, Commands\RouteCacheCommand::class, Commands\QueueCommands::class, Commands\ScheduleCommands::class, Commands\NewCommand::class, Commands\ModelCacheCommands::class, Commands\MessagingCommands::class,
            ] as $class
        ) {
            foreach ($class::instances($app) as $command) {
                $this->commands[$command->name()] = $command;
            }
        }
    }

    /** @param list<string> $argv full argv including script name */
    public function run(array $argv): int
    {
        $name = $argv[1] ?? 'list';
        if (in_array($name, ['list', 'help', '--help', '-h'], true)) {
            return $this->list();
        }
        if (!isset($this->commands[$name])) {
            $this->output->error("Command [{$name}] is not defined.");
            return 1;
        }
        try {
            $this->app->boot();
            return $this->commands[$name]->handle(new Input(array_slice($argv, 2)), $this->output);
        } catch (\Throwable $e) {
            $this->output->error($e->getMessage());
            return 1;
        }
    }

    private function list(): int
    {
        $this->output->line('NaluzPHP ' . Application::VERSION);
        $this->output->line();
        $this->output->warn('Usage: php naluz <command> [arguments] [--options]');
        $this->output->line();
        ksort($this->commands);
        foreach ($this->commands as $name => $command) {
            $this->output->line(sprintf('  %s  %s', str_pad($name, 22), $command->description()));
        }
        return 0;
    }
}
