<?php

declare(strict_types=1);

namespace Naluz\NoSql;

use Naluz\Config\Repository;
use Naluz\Foundation\ServiceProvider;

final class NoSqlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NoSqlManager::class, fn ($c) => new NoSqlManager($c->make(Repository::class), $c->basePath()));
        $this->app->bind(DocumentStore::class, fn ($c) => $c->make(NoSqlManager::class)->connection());
    }
}
