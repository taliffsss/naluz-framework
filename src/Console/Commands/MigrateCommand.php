<?php

declare(strict_types=1);

namespace Naluz\Console\Commands;

use Naluz\Console\Command;
use Naluz\Console\Input;
use Naluz\Console\Output;
use Naluz\Database\Connection;
use Naluz\Database\Migrations\Migrator;
use Naluz\Foundation\Application;

class MigrateCommand extends Command
{
    public static function instances(Application $app): array
    {
        return [new MigrateCommand($app), new MigrateRollbackCommand($app), new MigrateStatusCommand($app)];
    }

    public function name(): string
    {
        return 'migrate';
    }

    public function description(): string
    {
        return 'Run pending database migrations';
    }

    protected function migrator(): Migrator
    {
        return new Migrator($this->app->make(Connection::class), $this->app->basePath('database/migrations'));
    }

    public function handle(Input $input, Output $output): int
    {
        $ran = $this->migrator()->run();
        if ($ran === []) {
            $output->line('Nothing to migrate.');
        }
        foreach ($ran as $name) {
            $output->info("Migrated: {$name}");
        }
        return 0;
    }
}
