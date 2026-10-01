<?php

declare(strict_types=1);

namespace Naluz\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/** Delegates to PHP's error_log() — i.e. whatever the web server / php-fpm / `error_log` ini setting is configured to do. */
final class ErrorLogLogger extends AbstractLogger
{
    public function __construct(private readonly string $minLevel = LogLevel::DEBUG)
    {
        Levels::assert($minLevel);
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $level = Levels::assert((string) $level);
        if (Levels::atLeast($level, $this->minLevel)) {
            error_log(rtrim((new LineFormatter())->format($level, (string) $message, $context)));
        }
    }
}
