<?php

declare(strict_types=1);

namespace Naluz\Console\Commands;

use Naluz\Console\Command;
use Naluz\Console\Input;
use Naluz\Console\Output;
use Naluz\Database\Connection;
use Naluz\Database\Migrations\Migrator;
use Naluz\Database\Seeder;
use Naluz\Foundation\Application;

/** `db:seed` and `migrate:fresh [--seed]` — both refuse to run in production without --force. */
final class DbCommands extends Command
{
    public function __construct(Application $app, private readonly string $kind)
    {
        parent::__construct($app);
    }

    public static function instances(Application $app): array
    {
        return [new self($app, 'db:seed'), new self($app, 'migrate:fresh')];
    }

    public function name(): string
    {
        return $this->kind;
    }

    public function description(): string
    {
        return $this->kind === 'db:seed'
            ? 'Run database seeders (--class=Database\\Seeders\\DatabaseSeeder)'
            : 'Drop all tables and re-run every migration (--seed to seed afterwards)';
    }

    public function handle(Input $input, Output $output): int
    {
        if ($this->app->make(\Naluz\Config\Repository::class)->get('app.env') === 'production' && !$input->option('force')) {
            $output->error('Refusing to run in production without --force.');
            return 1;
        }
        if ($this->kind === 'migrate:fresh') {
            $db = $this->app->make(Connection::class);
            $db->schema()->dropAllTables();
            $output->warn('Dropped all tables.');
            foreach ((new Migrator($db, $this->app->basePath('database/migrations')))->run() as $name) {
                $output->info("Migrated: {$name}");
            }
            if (!$input->option('seed')) {
                return 0;
            }
        }
        return $this->seed($input, $output);
    }

    private function seed(Input $input, Output $output): int
    {
        $class = (string) $input->option('class', 'Database\\Seeders\\DatabaseSeeder');
        if (!class_exists($class) || !is_subclass_of($class, Seeder::class)) {
            $output->error("Seeder [{$class}] not found.");
            return 1;
        }
        $this->app->make($class)->run();
        $output->info("Seeded: {$class}");
        return 0;
    }
}
