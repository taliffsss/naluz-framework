<?php

declare(strict_types=1);

namespace Naluz\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/** Writes to a stream such as php://stderr (containers, Kubernetes, systemd/journald). */
final class StreamLogger extends AbstractLogger
{
    /** @var resource|null */
    private $stream = null;

    public function __construct(
        private readonly string $target = 'php://stderr',
        private readonly string $minLevel = LogLevel::DEBUG,
        private readonly Formatter $formatter = new LineFormatter(),
    ) {
        Levels::assert($minLevel);
        if (!in_array($target, ['php://stderr', 'php://stdout', 'php://output', 'php://memory'], true) && !str_starts_with($target, '/')) {
            throw new \InvalidArgumentException('Stream target must be php://stderr|stdout|output or an absolute path.');
        }
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $level = Levels::assert((string) $level);
        if (!Levels::atLeast($level, $this->minLevel)) {
            return;
        }
        $line = $this->formatter->format($level, (string) $message, $context);
        $this->stream ??= @fopen($this->target, 'ab') ?: null;
        if ($this->stream === null || @fwrite($this->stream, $line) === false) {
            error_log(rtrim($line));
        }
    }
}
