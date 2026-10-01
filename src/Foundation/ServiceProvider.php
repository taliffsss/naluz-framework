<?php

declare(strict_types=1);

namespace Naluz\Foundation;

abstract class ServiceProvider
{
    public function __construct(protected readonly Application $app)
    {
    }

    /** Bind services. Do not resolve other services here. */
    public function register(): void
    {
    }

    /** Called after every provider registered. */
    public function boot(): void
    {
    }
}
