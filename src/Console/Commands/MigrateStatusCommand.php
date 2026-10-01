<?php

declare(strict_types=1);

namespace Naluz\Console\Commands;

use Naluz\Console\Input;
use Naluz\Console\Output;
use Naluz\Foundation\Application;

final class MigrateStatusCommand extends MigrateCommand
{
    public static function instances(Application $app): array
    {
        return [];
    }

    public function name(): string
    {
        return 'migrate:status';
    }

    public function description(): string
    {
        return 'Show which migrations have run';
    }

    public function handle(Input $input, Output $output): int
    {
        $output->table(['Ran?', 'Migration'], array_map(fn ($r) => [$r['ran'] ? 'Yes' : 'No', $r['migration']], $this->migrator()->status()));
        return 0;
    }
}
