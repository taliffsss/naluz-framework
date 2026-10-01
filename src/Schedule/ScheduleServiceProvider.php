<?php

declare(strict_types=1);

namespace Naluz\Schedule;

use Naluz\Console\Kernel;
use Naluz\Console\Output;
use Naluz\Foundation\ServiceProvider;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

final class ScheduleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Schedule::class, function ($c) {
            $schedule = new Schedule(
                $c,
                $c->make(LoggerInterface::class),
                $c->make(CacheInterface::class),
                function (string $line) use ($c): int {
                    return (new Kernel($c, new Output(fopen('php://memory', 'w+'))))->run(['naluz', ...(preg_split('/\s+/', trim($line)) ?: [])]);
                }
            );
            if (is_file($file = $c->basePath('routes/console.php'))) {
                $define = require $file;
                if ($define instanceof \Closure) {
                    $define($schedule);
                }
            }
            return $schedule;
        });
    }
}
