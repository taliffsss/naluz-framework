<?php

declare(strict_types=1);

namespace Naluz\Storage;

use Naluz\Config\Repository;
use Naluz\Foundation\ServiceProvider;

final class StorageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StorageManager::class, fn ($c) => new StorageManager($c->make(Repository::class), $c->basePath()));
        $this->app->bind(Filesystem::class, fn ($c) => $c->make(StorageManager::class)->disk());
    }
}
