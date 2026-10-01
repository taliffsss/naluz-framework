<?php

declare(strict_types=1);

namespace Naluz\Console\Commands;

use Naluz\Console\Command;
use Naluz\Console\Input;
use Naluz\Console\Output;
use Naluz\Foundation\Application;
use Naluz\Routing\Router;

/** route:cache / route:clear / view:clear */
final class RouteCacheCommand extends Command
{
    public function __construct(Application $app, private readonly string $kind)
    {
        parent::__construct($app);
    }

    public static function instances(Application $app): array
    {
        return array_map(fn ($k) => new self($app, $k), ['route:cache', 'route:clear', 'view:clear']);
    }

    public function name(): string
    {
        return $this->kind;
    }

    public function description(): string
    {
        return match ($this->kind) {
            'route:cache' => 'Compile the route table for faster boots (controller routes only)',
            'route:clear' => 'Remove the route cache',
            default => 'Delete compiled templates',
        };
    }

    public function handle(Input $input, Output $output): int
    {
        if ($this->kind === 'view:clear') {
            $n = $this->app->make(\Naluz\View\Factory::class)->clearCache();
            $output->info("Removed {$n} compiled template(s).");
            return 0;
        }
        $path = $this->app->routeCachePath();
        if (is_file($path)) {
            unlink($path);
        }
        if ($this->kind === 'route:clear') {
            $output->info('Route cache cleared.');
            return 0;
        }

        $router = new Router($this->app);       // fresh router: never re-export an already cached table
        $this->app->registerRouteFiles($router);
        try {
            $table = $router->export();
        } catch (\LogicException $e) {
            $output->error($e->getMessage());
            return 1;
        }
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, "<?php\n\nreturn " . var_export($table, true) . ";\n");
        rename($tmp, $path);
        $output->info(count($table) . ' routes cached.');
        return 0;
    }
}
