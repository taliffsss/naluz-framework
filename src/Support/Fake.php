<?php

declare(strict_types=1);

namespace Naluz\Support;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Small fake-data generator for factories and seeders. `Fake::seed(42)` makes output reproducible.
 * (Not for security purposes — never use it to generate tokens or passwords.)
 */
final class Fake
{
    private static ?self $instance = null;
    private Randomizer $rng;
    private int $counter = 0;

    private const FIRST = ['Ana', 'Ben', 'Carla', 'Diego', 'Elena', 'Felix', 'Grace', 'Hugo', 'Isla', 'Jon', 'Kara', 'Liam', 'Mia', 'Noah', 'Olive', 'Paolo', 'Quinn', 'Rosa', 'Sam', 'Tess'];
    private const LAST = ['Santos', 'Reyes', 'Cruz', 'Garcia', 'Lopez', 'Navarro', 'Torres', 'Bautista', 'Rivera', 'Mendoza', 'Flores', 'Castillo'];
    private const WORDS = ['alpha', 'river', 'planet', 'quick', 'silver', 'forest', 'pixel', 'orbit', 'cobalt', 'ember', 'harbor', 'lumen', 'meadow', 'nimbus', 'quartz', 'summit', 'tundra', 'vector', 'willow', 'zephyr'];

    public function __construct(?int $seed = null)
    {
        $this->rng = $seed === null ? new Randomizer() : new Randomizer(new Xoshiro256StarStar(hash('sha256', (string) $seed, true)));
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public static function seed(?int $seed): void
    {
        self::$instance = new self($seed);
    }

    public function numberBetween(int $min = 0, int $max = 9999): int
    {
        return $this->rng->getInt($min, $max);
    }

    public function boolean(int $chanceOfTrue = 50): bool
    {
        return $this->numberBetween(1, 100) <= $chanceOfTrue;
    }

    /** @template T @param list<T> $items @return T */
    public function randomElement(array $items): mixed
    {
        return $items[$this->numberBetween(0, count($items) - 1)];
    }

    public function firstName(): string
    {
        return $this->randomElement(self::FIRST);
    }

    public function lastName(): string
    {
        return $this->randomElement(self::LAST);
    }

    public function name(): string
    {
        return $this->firstName() . ' ' . $this->lastName();
    }

    /** Unique within the process: a counter is mixed in. */
    public function unique(): int
    {
        return ++$this->counter;
    }

    public function email(): string
    {
        return strtolower($this->firstName() . '.' . $this->lastName()) . $this->unique() . '@example.test';
    }

    public function userName(): string
    {
        return strtolower($this->firstName()) . $this->numberBetween(10, 99) . $this->unique();
    }

    public function word(): string
    {
        return $this->randomElement(self::WORDS);
    }

    public function words(int $count = 3): string
    {
        return implode(' ', array_map(fn () => $this->word(), range(1, max(1, $count))));
    }

    public function slug(int $words = 3): string
    {
        return str_replace(' ', '-', $this->words($words)) . '-' . $this->unique();
    }

    public function sentence(int $words = 6): string
    {
        return ucfirst($this->words($words)) . '.';
    }

    public function paragraph(int $sentences = 3): string
    {
        return implode(' ', array_map(fn () => $this->sentence($this->numberBetween(4, 9)), range(1, max(1, $sentences))));
    }

    public function uuid(): string
    {
        $b = $this->rng->getBytes(16);
        $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
        $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    public function dateTimeBetween(string $from = '-1 year', string $to = 'now'): string
    {
        return date('Y-m-d H:i:s', $this->numberBetween(strtotime($from), strtotime($to)));
    }

    public function url(): string
    {
        return 'https://' . $this->word() . $this->unique() . '.example.test/' . $this->word();
    }

    public function decimal(float $min = 0, float $max = 1000, int $places = 2): float
    {
        return round($min + ($max - $min) * ($this->numberBetween(0, 1_000_000) / 1_000_000), $places);
    }
}
