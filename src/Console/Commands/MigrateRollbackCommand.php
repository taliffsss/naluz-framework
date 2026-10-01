<?php

declare(strict_types=1);

namespace Naluz\Console\Commands;

use Naluz\Console\Input;
use Naluz\Console\Output;
use Naluz\Foundation\Application;

final class MigrateRollbackCommand extends MigrateCommand
{
    public static function instances(Application $app): array
    {
        return [];
    }

    public function name(): string
    {
        return 'migrate:rollback';
    }

    public function description(): string
    {
        return 'Roll back the last migration batch (--step=N for more)';
    }

    public function handle(Input $input, Output $output): int
    {
        $rolled = $this->migrator()->rollback(max(1, (int) $input->option('step', '1')));
        if ($rolled === []) {
            $output->line('Nothing to roll back.');
        }
        foreach ($rolled as $name) {
            $output->info("Rolled back: {$name}");
        }
        return 0;
    }
}
