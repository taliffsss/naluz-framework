<?php

declare(strict_types=1);

namespace Naluz\Mail;

use Naluz\Config\Repository;
use Naluz\Foundation\ServiceProvider;
use Naluz\Queue\QueueManager;
use Naluz\View\Factory;
use Psr\Log\LoggerInterface;

final class MailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Transport::class, function ($c) {
            $cfg = $c->make(Repository::class);
            return match ($cfg->get('mail.default', 'log')) {
                'smtp' => new SmtpTransport(
                    (string) $cfg->get('mail.smtp.host', '127.0.0.1'),
                    (int) $cfg->get('mail.smtp.port', 587),
                    (string) $cfg->get('mail.smtp.encryption', 'tls'),
                    $cfg->get('mail.smtp.username') ?: null,
                    $cfg->get('mail.smtp.password') ?: null,
                    (float) $cfg->get('mail.smtp.timeout', 10),
                    helo: (string) ($cfg->get('mail.smtp.helo') ?: 'localhost')
                ),
                'array' => new ArrayTransport(),
                default => new LogTransport($c->make(LoggerInterface::class)),
            };
        });
        $this->app->singleton(Mailer::class, fn ($c) => new Mailer($c->make(Transport::class), $c->make(Repository::class), $c->make(Factory::class), $c->make(QueueManager::class)));
    }
}
