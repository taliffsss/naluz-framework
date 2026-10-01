<?php

declare(strict_types=1);

namespace Naluz\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/** Sends every record to several loggers. One failing destination (say Slack is down) never stops the others. */
final class StackLogger extends AbstractLogger
{
    /** @param list<LoggerInterface> $loggers */
    public function __construct(private readonly array $loggers)
    {
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        foreach ($this->loggers as $logger) {
            try {
                $logger->log($level, $message, $context);
            } catch (\Throwable $e) {
                error_log('[naluz] a log channel failed: ' . $e->getMessage());
            }
        }
    }
}
