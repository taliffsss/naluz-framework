<?php

declare(strict_types=1);

namespace Naluz\Routing;

use Naluz\Container\Container;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 middleware pipeline. Entries may be MiddlewareInterface instances, class names,
 * or aliases with parameters ("throttle:60,1").
 */
final class Pipeline
{
    /** @var list<MiddlewareInterface|string> */
    private array $stack = [];

    /** @param array<string,string> $aliases */
    public function __construct(private readonly Container $container, private readonly array $aliases = [])
    {
    }

    /** @param list<MiddlewareInterface|string> $middleware */
    public function through(array $middleware): self
    {
        $this->stack = [];
        foreach ($middleware as $entry) {
            if (!is_string($entry) || !in_array($entry, $this->stack, true)) {
                $this->stack[] = $entry;
            }
        }
        return $this;
    }

    public function then(RequestHandlerInterface $core): RequestHandlerInterface
    {
        $handler = $core;
        foreach (array_reverse($this->stack) as $entry) {
            $handler = $this->wrap($this->resolve($entry), $handler);
        }
        return $handler;
    }

    private function resolve(MiddlewareInterface|string $entry): MiddlewareInterface
    {
        if ($entry instanceof MiddlewareInterface) {
            return $entry;
        }
        [$name, $args] = array_pad(explode(':', $entry, 2), 2, null);
        $class = $this->aliases[$name] ?? $name;
        $parameters = $args === null ? [] : explode(',', $args);
        $middleware = $this->container->make($class, ['parameters' => $parameters]);
        if (!$middleware instanceof MiddlewareInterface) {
            throw new \InvalidArgumentException("[{$class}] is not a PSR-15 middleware.");
        }
        return $middleware;
    }

    private function wrap(MiddlewareInterface $middleware, RequestHandlerInterface $next): RequestHandlerInterface
    {
        return new class ($middleware, $next) implements RequestHandlerInterface {
            public function __construct(private MiddlewareInterface $m, private RequestHandlerInterface $next)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->m->process($request, $this->next);
            }
        };
    }
}
