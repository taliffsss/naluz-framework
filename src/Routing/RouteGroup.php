<?php

declare(strict_types=1);

namespace Naluz\Routing;

/**
 * Registrar returned by Router::prefix()/middleware()/name() so groups can be chained fluently:
 *   $router->prefix('api')->middleware('auth')->group(fn ($r) => ...);
 */
final class RouteGroup
{
    public function __construct(private readonly Router $router, private array $attributes = [])
    {
    }

    public function prefix(string $prefix): self
    {
        return new self($this->router, [...$this->attributes, 'prefix' => ($this->attributes['prefix'] ?? '') . '/' . trim($prefix, '/')]);
    }

    /** @param string|list<string> $middleware */
    public function middleware(string|array $middleware): self
    {
        return new self($this->router, [...$this->attributes, 'middleware' => [...($this->attributes['middleware'] ?? []), ...(array) $middleware]]);
    }

    public function name(string $prefix): self
    {
        return new self($this->router, [...$this->attributes, 'name' => ($this->attributes['name'] ?? '') . $prefix]);
    }

    public function group(\Closure $routes): void
    {
        $this->router->group($this->attributes, $routes);
    }
}
