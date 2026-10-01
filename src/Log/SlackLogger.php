<?php

declare(strict_types=1);

namespace Naluz\Log;

use Naluz\Http\Client\Http;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Posts records to a Slack incoming webhook (through the framework's PSR-18 client).
 *
 * - Defaults to `critical` and above: Slack is for things that need a human, not for debug noise.
 * - Never throws: a Slack outage must not break your app; the record falls back to error_log().
 * - Message text is escaped (`& < >`), so user-controlled log content can't trigger `<!channel>` pings or fake links.
 * - Stack traces are NOT sent unless `includeTrace` is on (traces can reveal paths and internals).
 * - The webhook URL is a secret: it is never written to any log.
 */
final class SlackLogger extends AbstractLogger
{
    private const COLORS = [
        'debug' => '#9e9e9e', 'info' => '#2196f3', 'notice' => '#00bcd4', 'warning' => '#ff9800',
        'error' => '#f44336', 'critical' => '#d50000', 'alert' => '#b71c1c', 'emergency' => '#000000',
    ];

    public function __construct(
        private readonly string $webhookUrl,
        private readonly Http $http,
        private readonly string $minLevel = LogLevel::CRITICAL,
        private readonly string $username = 'NaluzPHP',
        private readonly string $emoji = ':rotating_light:',
        private readonly string $appName = 'app',
        private readonly bool $includeTrace = false,
    ) {
        Levels::assert($minLevel);
        if (!preg_match('#^https://hooks(\.slack|-gov\.slack)\.com/#', $webhookUrl)) {
            throw new \InvalidArgumentException('Slack webhook URL must start with https://hooks.slack.com/.');
        }
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $level = Levels::assert((string) $level);
        if (!Levels::atLeast($level, $this->minLevel)) {
            return;
        }
        $line = rtrim((new LineFormatter())->format($level, (string) $message, $context));
        try {
            $response = $this->http->post($this->webhookUrl, $this->payload($level, $message, $context));
            if ($response->getStatusCode() >= 300) {
                error_log('[naluz] Slack log channel got HTTP ' . $response->getStatusCode() . ' — ' . $line);
            }
        } catch (\Throwable $e) {
            error_log('[naluz] Slack log channel failed (' . $e::class . ') — ' . $line);
        }
    }

    /** @return array<string,mixed> */
    private function payload(string $level, string|\Stringable $message, array $context): array
    {
        $withoutException = $context;
        unset($withoutException['exception']); // class and basename:line go into fields; full paths stay out of Slack
        $interpolated = rtrim((new LineFormatter())->format($level, (string) $message, $withoutException));
        $interpolated = (string) preg_replace('/^\[[^\]]*\] [A-Z]+: /', '', $interpolated);
        $attachment = ['color' => self::COLORS[$level], 'text' => $this->escape($interpolated), 'mrkdwn_in' => ['text']];

        $e = $context['exception'] ?? null;
        if ($e instanceof \Throwable) {
            $attachment['fields'] = [
                ['title' => 'Exception', 'value' => $this->escape($e::class), 'short' => true],
                ['title' => 'Location', 'value' => $this->escape(basename($e->getFile()) . ':' . $e->getLine()), 'short' => true],
            ];
            if ($this->includeTrace) {
                $attachment['text'] .= "\n```" . $this->escape(mb_substr($e->getTraceAsString(), 0, 1500)) . '```';
            }
        }
        return [
            'username' => $this->username,
            'text' => sprintf('%s *%s* in %s', $this->emoji, strtoupper($level), $this->escape($this->appName)),
            'attachments' => [$attachment],
        ];
    }

    private function escape(string $text): string
    {
        return mb_substr(str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text), 0, 2800);
    }
}
