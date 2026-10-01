<?php

declare(strict_types=1);

namespace Naluz\Console\Commands;

use Naluz\Console\Command;
use Naluz\Console\Input;
use Naluz\Console\Output;
use Naluz\Foundation\Application;
use Naluz\Routing\Router;

final class RouteListCommand extends Command
{
    public static function instances(Application $app): array
    {
        return [new self($app)];
    }

    public function name(): string
    {
        return 'route:list';
    }

    public function description(): string
    {
        return 'List every registered route';
    }

    public function handle(Input $input, Output $output): int
    {
        $rows = [];
        foreach ($this->app->make(Router::class)->all() as $route) {
            $action = $route->action instanceof \Closure ? 'Closure'
                : (is_array($route->action) ? $route->action[0] . '@' . $route->action[1] : (string) $route->action);
            $rows[] = [implode('|', array_diff($route->methods, ['HEAD'])), $route->uri, $route->name !== null ? $route->groupName . $route->name : '', $action, implode(',', $route->middleware)];
        }
        $output->table(['Method', 'URI', 'Name', 'Action', 'Middleware'], $rows);
        return 0;
    }
}
