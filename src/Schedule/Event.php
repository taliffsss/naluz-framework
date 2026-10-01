<?php

declare(strict_types=1);

namespace Naluz\Schedule;

/** One scheduled task plus its frequency, filters and overlap policy. */
final class Event
{
    private string $expression = '* * * * *';
    /** @var list<\Closure> */
    private array $filters = [];
    private ?int $overlapTtl = null;
    private string $description;

    /** @param \Closure():mixed $run */
    public function __construct(public readonly \Closure $run, string $description)
    {
        $this->description = $description;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function name(string $name): self
    {
        $this->description = $name;
        return $this;
    }

    public function expression(): string
    {
        return $this->expression;
    }

    public function cron(string $expression): self
    {
        new CronExpression($expression); // validate eagerly
        $this->expression = $expression;
        return $this;
    }

    public function everyMinute(): self
    {
        return $this->cron('* * * * *');
    }

    public function everyFiveMinutes(): self
    {
        return $this->cron('*/5 * * * *');
    }

    public function everyTenMinutes(): self
    {
        return $this->cron('*/10 * * * *');
    }

    public function everyFifteenMinutes(): self
    {
        return $this->cron('*/15 * * * *');
    }

    public function everyThirtyMinutes(): self
    {
        return $this->cron('*/30 * * * *');
    }

    public function hourly(): self
    {
        return $this->cron('0 * * * *');
    }

    public function hourlyAt(int $minute): self
    {
        return $this->cron("{$minute} * * * *");
    }

    public function daily(): self
    {
        return $this->cron('0 0 * * *');
    }

    /** @param string $time "HH:MM" */
    public function dailyAt(string $time): self
    {
        [$h, $m] = $this->time($time);
        return $this->cron("{$m} {$h} * * *");
    }

    public function weekly(): self
    {
        return $this->cron('0 0 * * 0');
    }

    /** @param int $day 0 (Sunday) – 6 */
    public function weeklyOn(int $day, string $time = '00:00'): self
    {
        [$h, $m] = $this->time($time);
        return $this->cron("{$m} {$h} * * {$day}");
    }

    public function monthly(): self
    {
        return $this->cron('0 0 1 * *');
    }

    public function monthlyOn(int $day, string $time = '00:00'): self
    {
        [$h, $m] = $this->time($time);
        return $this->cron("{$m} {$h} {$day} * *");
    }

    public function weekdays(): self
    {
        $parts = explode(' ', $this->expression);
        $parts[4] = '1-5';
        return $this->cron(implode(' ', $parts));
    }

    /** @return array{0:int,1:int} */
    private function time(string $time): array
    {
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $m)) {
            throw new \InvalidArgumentException("Invalid time [{$time}], expected HH:MM.");
        }
        return [(int) $m[1], (int) $m[2]];
    }

    /** Only run when the callback returns true. */
    public function when(\Closure $filter): self
    {
        $this->filters[] = $filter;
        return $this;
    }

    public function skip(\Closure $filter): self
    {
        $this->filters[] = fn () => !$filter();
        return $this;
    }

    /** Don't start a new run while the previous one is still going (lock expires after $minutes as a safety net). */
    public function withoutOverlapping(int $minutes = 60): self
    {
        $this->overlapTtl = max(1, $minutes) * 60;
        return $this;
    }

    public function overlapTtl(): ?int
    {
        return $this->overlapTtl;
    }

    public function lockKey(): string
    {
        return 'schedule_' . sha1($this->description . '|' . $this->expression);
    }

    public function isDue(\DateTimeInterface $now): bool
    {
        if (!(new CronExpression($this->expression))->isDue($now)) {
            return false;
        }
        foreach ($this->filters as $filter) {
            if (!$filter()) {
                return false;
            }
        }
        return true;
    }

    public function nextRun(\DateTimeInterface $from): \DateTimeImmutable
    {
        return (new CronExpression($this->expression))->nextRunDate($from);
    }
}
