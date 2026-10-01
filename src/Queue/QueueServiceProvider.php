<?php

declare(strict_types=1);

namespace Naluz\Queue;

use Naluz\Config\Repository;
use Naluz\Database\Connection;
use Naluz\Foundation\ServiceProvider;
use Naluz\Security\Encrypter;

final class QueueServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;
        $app->singleton(Payload::class, fn ($c) => new Payload($c->make(Encrypter::class)));
        $app->singleton(QueueManager::class, fn ($c) => new QueueManager($c, $c->make(Repository::class)));
        $app->singleton(FailedJobs::class, fn ($c) => new FailedJobs($c->make(Connection::class)));
        $app->alias(Queue::class, QueueManager::class);
    }
}
