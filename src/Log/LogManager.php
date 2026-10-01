<?php

declare(strict_types=1);

namespace Naluz\Log;

use Naluz\Config\Repository;
use Naluz\Container\Container;
use Naluz\Http\Client\Http;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The application's PSR-3 logger. Calling it logs to the default channel; pick another with `channel('slack')`.
 * Channels are defined in `config/logging.php`.
 *
 *   logger()->warning('Disk almost full');
 *   logger()->channel('slack')->critical('Payment provider down');
 *   logger()->stack(['daily', 'slack'])->error('...');   // ad-hoc stack
 */
final class LogManager extends AbstractLogger
{
    /** @var array<string,LoggerInterface> */
    private array $channels = [];
    /** @var array<string,true> */
    private array $building = [];

    public function __construct(private readonly Container $container, private readonly Repository $config)
    {
    }

    public function channel(?string $name = null): LoggerInterface
    {
        $name ??= (string) $this->config->get('logging.default', 'daily');
        return $this->channels[$name] ??= $this->build($name);
    }

    /** Register a ready-made logger under a channel name (also handy in tests). */
    public function extend(string $name, LoggerInterface $logger): void
    {
        $this->channels[$name] = $logger;
    }

    /** @param list<string> $names */
    public function stack(array $names): LoggerInterface
    {
        return new StackLogger(array_map(fn (string $n) => $this->channel($n), $names));
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->channel()->log($level, $message, $context);
    }

    private function build(string $name): LoggerInterface
    {
        $cfg = $this->config->get("logging.channels.{$name}")
            ?? throw new \InvalidArgumentException("Log channel [{$name}] is not configured.");
        if (isset($this->building[$name])) {
            throw new \LogicException("Log channel [{$name}] includes itself.");
        }
        $this->building[$name] = true;
        try {
            return $this->make($name, (array) $cfg);
        } finally {
            unset($this->building[$name]);
        }
    }

    private function make(string $name, array $c): LoggerInterface
    {
        $level = (string) ($c['level'] ?? 'debug');
        $formatter = ($c['format'] ?? 'line') === 'json' ? new JsonFormatter() : new LineFormatter();
        $dir = $this->container->basePath('storage/logs');

        return match ($c['driver'] ?? null) {
            'single' => new FileLogger(
                $this->path($c['path'] ?? $dir),
                $level,
                false,
                0,
                basename((string) ($c['file'] ?? 'naluz.log')),
                $formatter
            ),
            'daily' => new FileLogger($this->path($c['path'] ?? $dir), $level, true, (int) ($c['days'] ?? 14), null, $formatter),
            'stderr' => new StreamLogger('php://stderr', $level, $formatter),
            'stdout' => new StreamLogger('php://stdout', $level, $formatter),
            'errorlog' => new ErrorLogLogger($level),
            'null' => new NullLogger(),
            'slack' => new SlackLogger(
                (string) ($c['url'] ?? ''),
                $this->container->make(Http::class),
                $level === 'debug' ? 'critical' : $level, // Slack default: critical+ unless a level is set
                (string) ($c['username'] ?? 'NaluzPHP'),
                (string) ($c['emoji'] ?? ':rotating_light:'),
                (string) $this->config->get('app.name', 'app') . ' (' . $this->config->get('app.env', 'production') . ')',
                (bool) ($c['include_trace'] ?? false)
            ),
            'stack' => new StackLogger(array_map(fn (string $n) => $this->channel($n), (array) ($c['channels'] ?? []))),
            'custom' => $this->custom($name, $c),
            default => throw new \InvalidArgumentException("Log channel [{$name}] has an unsupported driver."),
        };
    }

    private function custom(string $name, array $c): LoggerInterface
    {
        $via = $c['via'] ?? null;
        $logger = $via instanceof \Closure ? $via($c) : (is_string($via) ? $this->container->make($via) : null);
        return $logger instanceof LoggerInterface
            ? $logger
            : throw new \InvalidArgumentException("Log channel [{$name}]: `via` must produce a PSR-3 LoggerInterface.");
    }

    private function path(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->container->basePath($path);
    }
}
