<?php

declare(strict_types=1);

namespace Naluz\Log;

use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;

/** PSR-3 level ordering shared by every logger. */
final class Levels
{
    public const ORDER = [
        LogLevel::DEBUG => 0, LogLevel::INFO => 1, LogLevel::NOTICE => 2, LogLevel::WARNING => 3,
        LogLevel::ERROR => 4, LogLevel::CRITICAL => 5, LogLevel::ALERT => 6, LogLevel::EMERGENCY => 7,
    ];

    public static function assert(string $level): string
    {
        $level = strtolower($level);
        if (!isset(self::ORDER[$level])) {
            throw new InvalidArgumentException("Unknown log level [{$level}].");
        }
        return $level;
    }

    public static function atLeast(string $level, string $minimum): bool
    {
        return self::ORDER[self::assert($level)] >= self::ORDER[self::assert($minimum)];
    }
}
