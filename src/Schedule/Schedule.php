<?php

declare(strict_types=1);

namespace Naluz\Schedule;

use Naluz\Cache\Incrementable;
use Naluz\Container\Container;
use Naluz\Queue\Job;
use Naluz\Queue\QueueManager;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Define tasks in routes/console.php; run `* * * * * php /path/to/naluz schedule:run` from cron.
 *
 *   $schedule->job(new PruneSessions())->dailyAt('03:00');
 *   $schedule->command('queue:work --stop-when-empty')->everyMinute()->withoutOverlapping();
 *   $schedule->call(fn () => Cache::clear())->hourly();
 */
final class Schedule
{
    /** @var list<Event> */
    private array $events = [];

    /** @param (\Closure(string):int)|null $commandRunner runs a naluz CLI command line, returns exit code */
    public function __construct(
        private readonly Container $container,
        private readonly LoggerInterface $logger,
        private readonly CacheInterface $cache,
        private readonly ?\Closure $commandRunner = null,
    ) {
    }

    public function call(callable $callback, ?string $description = null): Event
    {
        $closure = $callback instanceof \Closure ? $callback : \Closure::fromCallable($callback);
        return $this->events[] = new Event(fn () => $this->container->call($closure), $description ?? 'callback');
    }

    /** Dispatch a queued job. */
    public function job(Job $job): Event
    {
        return $this->events[] = new Event(fn () => $this->container->make(QueueManager::class)->dispatch($job), $job::class);
    }

    /** Run a naluz command, e.g. `queue:work --stop-when-empty`. */
    public function command(string $commandLine): Event
    {
        $runner = $this->commandRunner ?? throw new \LogicException('No command runner available.');
        return $this->events[] = new Event(function () use ($runner, $commandLine) {
            $code = $runner($commandLine);
            if ($code !== 0) {
                throw new \RuntimeException("Command [{$commandLine}] exited with code {$code}.");
            }
        }, $commandLine);
    }

    /** @return list<Event> */
    public function events(): array
    {
        return $this->events;
    }

    /** @return list<Event> */
    public function dueEvents(\DateTimeInterface $now): array
    {
        return array_values(array_filter($this->events, fn (Event $e) => $e->isDue($now)));
    }

    /**
     * Run everything due at $now. One failing task never prevents the others from running.
     *
     * @return array<string,string> description => ran|skipped (overlap)|failed
     */
    public function run(\DateTimeInterface $now): array
    {
        $results = [];
        foreach ($this->dueEvents($now) as $event) {
            $key = $event->lockKey();
            $ttl = $event->overlapTtl();
            if ($ttl !== null && $this->cache instanceof Incrementable) {
                if ($this->cache->increment($key, $ttl) > 1) {
                    $results[$event->description()] = 'skipped';
                    continue;
                }
            }
            try {
                ($event->run)();
                $results[$event->description()] = 'ran';
            } catch (\Throwable $e) {
                $results[$event->description()] = 'failed';
                $this->logger->error('Scheduled task [{task}] failed: {m}', ['task' => $event->description(), 'm' => $e->getMessage(), 'exception' => $e]);
            } finally {
                if ($ttl !== null) {
                    $this->cache->delete($key);
                }
            }
        }
        return $results;
    }
}
