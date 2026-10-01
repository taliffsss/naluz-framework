<?php

declare(strict_types=1);

namespace Naluz\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * PSR-3 file logger. Daily files (`naluz-2026-01-31.log`, optional retention) or a single file.
 * If the file cannot be written the record goes to PHP's error_log(), never nowhere.
 */
final class FileLogger extends AbstractLogger
{
    private bool $pruned = false;

    /**
     * @param int $days daily files older than this are deleted (0 = keep everything)
     * @param string|null $file fixed file name (disables daily rotation), e.g. "app.log"
     */
    public function __construct(
        private readonly string $directory,
        private readonly string $minLevel = LogLevel::DEBUG,
        private readonly bool $daily = true,
        private readonly int $days = 0,
        private readonly ?string $file = null,
        private readonly Formatter $formatter = new LineFormatter(),
    ) {
        Levels::assert($minLevel);
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $level = Levels::assert((string) $level);
        if (!Levels::atLeast($level, $this->minLevel)) {
            return;
        }
        $line = $this->formatter->format($level, (string) $message, $context);
        $writable = is_dir($this->directory) || @mkdir($this->directory, 0775, true) || is_dir($this->directory);
        // never lose a record silently: unwritable log file -> PHP's own error log
        if (!$writable || @file_put_contents($this->path(), $line, FILE_APPEND | LOCK_EX) === false) {
            error_log(rtrim($line));
            return;
        }
        $this->prune();
    }

    private function path(): string
    {
        if ($this->file !== null) {
            return $this->directory . '/' . basename($this->file);
        }
        return $this->directory . '/naluz' . ($this->daily ? '-' . date('Y-m-d') : '') . '.log';
    }

    private function prune(): void
    {
        if ($this->pruned || !$this->daily || $this->days <= 0 || $this->file !== null) {
            return;
        }
        $this->pruned = true; // at most once per process
        $cutoff = time() - $this->days * 86400;
        foreach (glob($this->directory . '/naluz-????-??-??.log') ?: [] as $old) {
            if (filemtime($old) < $cutoff) {
                @unlink($old);
            }
        }
    }
}
