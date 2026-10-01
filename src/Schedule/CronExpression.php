<?php

declare(strict_types=1);

namespace Naluz\Schedule;

/**
 * Five-field cron expression: `minute hour day-of-month month day-of-week`.
 * Supports `*`, lists (1,15), ranges (1-5), steps (star/5, 10-30/10) and month / weekday names (JAN, MON).
 * When both day-of-month and day-of-week are restricted, either matching is enough (standard cron semantics).
 */
final class CronExpression
{
    private const NAMES = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
        'sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6,
    ];
    private const MACROS = ['@hourly' => '0 * * * *', '@daily' => '0 0 * * *', '@midnight' => '0 0 * * *', '@weekly' => '0 0 * * 0', '@monthly' => '0 0 1 * *', '@yearly' => '0 0 1 1 *', '@annually' => '0 0 1 1 *'];

    /** @var array<int,array<int,true>> */
    private array $fields;
    private bool $domRestricted;
    private bool $dowRestricted;

    public function __construct(private readonly string $expression)
    {
        $expr = self::MACROS[strtolower(trim($expression))] ?? trim($expression);
        $parts = preg_split('/\s+/', $expr) ?: [];
        if (count($parts) !== 5) {
            throw new \InvalidArgumentException("Invalid cron expression [{$expression}]: expected 5 fields.");
        }
        $ranges = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];
        $this->fields = [];
        foreach ($parts as $i => $part) {
            $this->fields[$i] = $this->parseField($part, $ranges[$i][0], $ranges[$i][1], $expression);
        }
        // day-of-week 7 is Sunday too
        if (isset($this->fields[4][7])) {
            $this->fields[4][0] = true;
            unset($this->fields[4][7]);
        }
        $this->domRestricted = $parts[2] !== '*';
        $this->dowRestricted = $parts[4] !== '*';
    }

    public static function isValid(string $expression): bool
    {
        try {
            new self($expression);
            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    public function __toString(): string
    {
        return $this->expression;
    }

    /** @return array<int,true> */
    private function parseField(string $field, int $min, int $max, string $whole): array
    {
        $set = [];
        foreach (explode(',', strtolower($field)) as $item) {
            if (!preg_match('~^(\*|[a-z0-9]+(?:-[a-z0-9]+)?)(?:/(\d+))?$~', $item, $m)) {
                throw new \InvalidArgumentException("Invalid cron expression [{$whole}].");
            }
            $step = isset($m[2]) ? (int) $m[2] : 1;
            if ($step < 1) {
                throw new \InvalidArgumentException("Invalid cron step in [{$whole}].");
            }
            if ($m[1] === '*') {
                [$from, $to] = [$min, $max === 7 ? 6 : $max];
            } else {
                $bounds = explode('-', $m[1]);
                $from = $this->number($bounds[0], $min, $max, $whole);
                $to = isset($bounds[1]) ? $this->number($bounds[1], $min, $max, $whole) : (isset($m[2]) ? $max : $from);
                if ($from > $to) {
                    throw new \InvalidArgumentException("Invalid cron range in [{$whole}].");
                }
            }
            for ($v = $from; $v <= $to; $v += $step) {
                $set[$v] = true;
            }
        }
        return $set;
    }

    private function number(string $token, int $min, int $max, string $whole): int
    {
        $n = self::NAMES[$token] ?? (ctype_digit($token) ? (int) $token : null);
        if ($n === null || $n < $min || $n > $max) {
            throw new \InvalidArgumentException("Cron value [{$token}] out of range in [{$whole}].");
        }
        return $n;
    }

    public function isDue(\DateTimeInterface $at): bool
    {
        [$minute, $hour, $dom, $month, $dow] = array_map('intval', explode(' ', $at->format('i G j n w')));
        if (!isset($this->fields[0][$minute], $this->fields[1][$hour], $this->fields[3][$month])) {
            return false;
        }
        $domOk = isset($this->fields[2][$dom]);
        $dowOk = isset($this->fields[4][$dow]);
        return ($this->domRestricted && $this->dowRestricted) ? ($domOk || $dowOk) : ($domOk && $dowOk);
    }

    /** Next matching minute strictly after $from (searches up to ~5 years ahead). */
    public function nextRunDate(\DateTimeInterface $from): \DateTimeImmutable
    {
        $t = \DateTimeImmutable::createFromInterface($from)->setTime((int) $from->format('G'), (int) $from->format('i'), 0)->modify('+1 minute');
        $limit = $t->modify('+5 years');
        while ($t < $limit) {
            if (!isset($this->fields[3][(int) $t->format('n')])) {
                $t = $t->modify('first day of next month midnight');
                continue;
            }
            $dom = (int) $t->format('j');
            $dow = (int) $t->format('w');
            $domOk = isset($this->fields[2][$dom]);
            $dowOk = isset($this->fields[4][$dow]);
            $dayOk = ($this->domRestricted && $this->dowRestricted) ? ($domOk || $dowOk) : ($domOk && $dowOk);
            if (!$dayOk) {
                $t = $t->modify('tomorrow midnight');
                continue;
            }
            if (!isset($this->fields[1][(int) $t->format('G')])) {
                $t = $t->setTime((int) $t->format('G'), 0)->modify('+1 hour');
                continue;
            }
            if (!isset($this->fields[0][(int) $t->format('i')])) {
                $t = $t->modify('+1 minute');
                continue;
            }
            return $t;
        }
        throw new \RuntimeException("Cron expression [{$this->expression}] never matches.");
    }
}
