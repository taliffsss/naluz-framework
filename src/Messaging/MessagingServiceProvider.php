<?php

declare(strict_types=1);

namespace Naluz\Messaging;

use Naluz\Config\Repository;
use Naluz\Foundation\ServiceProvider;
use Psr\Log\LoggerInterface;

final class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;
        $app->singleton(Codec::class, function ($c) {
            $cfg = $c->make(Repository::class);
            return new Codec(
                (string) $cfg->get('messaging.signing_key', ''),
                array_values(array_filter((array) $cfg->get('messaging.previous_signing_keys', []), 'is_string')),
                (int) $cfg->get('messaging.max_bytes', 1_048_576),
            );
        });
        $app->singleton(BrokerManager::class, fn ($c) => new BrokerManager($c, $c->make(Repository::class)));
        $app->singleton(EventBus::class, fn ($c) => new EventBus($c->make(BrokerManager::class), $c->make(Codec::class), $c->make(Repository::class)));
        $app->singleton(Consumer::class, fn ($c) => new Consumer(
            $c,
            $c->make(BrokerManager::class),
            $c->make(EventBus::class),
            $c->make(Codec::class),
            $c->make(Repository::class),
            $c->make(LoggerInterface::class),
        ));
    }
}
