<?php

declare(strict_types=1);

namespace Naluz\Routing;

use Naluz\Container\Container;
use Naluz\Http\HttpException;
use Naluz\Http\ResponseFactory;
use Naluz\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class Router implements RequestHandlerInterface
{
    /** @var array<string,list<Route>> routes indexed by HTTP method */
    private array $routes = [];
    /** @var array<string,Route> */
    private array $named = [];
    /** @var list<Route> every route in registration order (needed by the route cache) */
    private array $ordered = [];
    /** @var array<string,string> */
    private array $aliases = [];
    /** @var array<string,list<string>> */
    private array $groups = [];
    /** @var list<array{prefix?:string,middleware?:list<string>,name?:string}> */
    private array $groupStack = [];

    public function __construct(private readonly Container $container)
    {
    }

    /** Register a short alias usable in route middleware lists, e.g. 'auth' => AuthMiddleware::class. */
    public function aliasMiddleware(string $alias, string $class): void
    {
        $this->aliases[$alias] = $class;
    }

    /** Name a bundle of middleware, e.g. 'web' => ['session', 'csrf']. */
    public function middlewareGroup(string $name, array $middleware): void
    {
        $this->groups[$name] = $middleware;
    }

    private function expand(array $middleware): array
    {
        $out = [];
        foreach ($middleware as $entry) {
            if (is_string($entry) && isset($this->groups[$entry])) {
                array_push($out, ...$this->expand($this->groups[$entry]));
            } else {
                $out[] = $entry;
            }
        }
        return $out;
    }

    public function get(string $uri, mixed $action): Route
    {
        return $this->add(['GET', 'HEAD'], $uri, $action);
    }

    public function post(string $uri, mixed $action): Route
    {
        return $this->add(['POST'], $uri, $action);
    }

    public function put(string $uri, mixed $action): Route
    {
        return $this->add(['PUT'], $uri, $action);
    }

    public function patch(string $uri, mixed $action): Route
    {
        return $this->add(['PATCH'], $uri, $action);
    }

    public function delete(string $uri, mixed $action): Route
    {
        return $this->add(['DELETE'], $uri, $action);
    }

    public function options(string $uri, mixed $action): Route
    {
        return $this->add(['OPTIONS'], $uri, $action);
    }

    public function any(string $uri, mixed $action): Route
    {
        return $this->add(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], $uri, $action);
    }

    /** @param list<string> $methods */
    public function match(array $methods, string $uri, mixed $action): Route
    {
        return $this->add(array_map('strtoupper', $methods), $uri, $action);
    }

    /**
     * @param list<string> $methods
     */
    public function add(array $methods, string $uri, mixed $action): Route
    {
        $prefix = '';
        $middleware = [];
        $name = '';
        foreach ($this->groupStack as $g) {
            $prefix .= '/' . trim($g['prefix'] ?? '', '/');
            array_push($middleware, ...($g['middleware'] ?? []));
            $name .= $g['name'] ?? '';
        }
        $route = new Route($methods, $prefix . '/' . trim($uri, '/'), $action);
        $route->middleware($middleware);
        $route->groupName = $name;

        $this->register($route);
        return $route;
    }

    private function register(Route $route): void
    {
        $this->ordered[] = $route;
        foreach ($route->methods as $method) {
            $this->routes[$method][] = $route;
        }
    }

    /**
     * Serialisable route table for `route:cache`. Closures cannot be cached, so they are rejected up front.
     *
     * @return list<array<string,mixed>>
     */
    public function export(): array
    {
        $closures = [];
        $out = [];
        foreach ($this->ordered as $route) {
            if ($route->action instanceof \Closure || !(is_string($route->action) || (is_array($route->action) && count($route->action) === 2 && is_string($route->action[0]) && is_string($route->action[1])))) {
                $closures[] = implode('|', $route->methods) . ' ' . $route->uri;
                continue;
            }
            $out[] = [
                'methods' => $route->methods,
                'uri' => $route->uri,
                'action' => $route->action,
                'middleware' => $route->middleware,
                'wheres' => $route->wheres,
                'name' => $route->name,
                'groupName' => $route->groupName,
            ];
        }
        if ($closures !== []) {
            throw new \LogicException("Cannot cache routes that use closures:\n  - " . implode("\n  - ", $closures) . "\nMove them into controller classes.");
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $table */
    public function loadCached(array $table): void
    {
        foreach ($table as $r) {
            $route = new Route($r['methods'], $r['uri'], $r['action']);
            $route->middleware = $r['middleware'];
            $route->wheres = $r['wheres'];
            $route->name = $r['name'];
            $route->groupName = $r['groupName'];
            $this->register($route);
        }
    }

    /** @param array{prefix?:string,middleware?:string|list<string>,name?:string} $attributes */
    public function group(array $attributes, \Closure $routes): void
    {
        $attributes['middleware'] = (array) ($attributes['middleware'] ?? []);
        $this->groupStack[] = $attributes;
        try {
            $routes($this);
        } finally {
            array_pop($this->groupStack);
        }
    }

    public function prefix(string $prefix): RouteGroup
    {
        return (new RouteGroup($this))->prefix($prefix);
    }

    /** @param string|list<string> $middleware */
    public function middleware(string|array $middleware): RouteGroup
    {
        return (new RouteGroup($this))->middleware($middleware);
    }

    /**
     * RESTful resource: index, create, store, show, edit, update, destroy.
     *
     * @param list<string>|null $only
     */
    public function resource(string $name, string $controller, ?array $only = null, bool $api = false): void
    {
        $base = trim($name, '/');
        $param = '{' . Str::singular(basename($base)) . '}';
        $map = [
            'index' => ['GET', $base, false],
            'create' => ['GET', "$base/create", true],
            'store' => ['POST', $base, false],
            'show' => ['GET', "$base/$param", false],
            'edit' => ['GET', "$base/$param/edit", true],
            'update' => ['PUT', "$base/$param", false],
            'destroy' => ['DELETE', "$base/$param", false],
        ];
        foreach ($map as $method => [$verb, $uri, $htmlOnly]) {
            if (($api && $htmlOnly) || ($only !== null && !in_array($method, $only, true))) {
                continue;
            }
            $this->add([$verb], $uri, [$controller, $method])->name(str_replace('/', '.', $base) . '.' . $method);
            if ($method === 'update') {
                $this->add(['PATCH'], $uri, [$controller, $method]);
            }
        }
    }

    /** @param list<string>|null $only */
    public function apiResource(string $name, string $controller, ?array $only = null): void
    {
        $this->resource($name, $controller, $only, true);
    }

    /** Resolve the route for a request. @return array{0:Route,1:array<string,string>} */
    public function resolve(ServerRequestInterface $request): array
    {
        $method = strtoupper($request->getMethod());
        $path = '/' . trim(rawurldecode($request->getUri()->getPath()), '/');

        foreach ($this->routes[$method] ?? [] as $route) {
            if (($params = $route->match($path)) !== null) {
                return [$route, $params];
            }
        }

        $allowed = [];
        foreach ($this->routes as $m => $routes) {
            foreach ($routes as $route) {
                if ($route->match($path) !== null) {
                    $allowed[] = $m;
                    break;
                }
            }
        }
        if ($allowed !== []) {
            throw new HttpException(405, 'Method Not Allowed', ['Allow' => implode(', ', $allowed)]);
        }
        throw new HttpException(404, 'Not Found');
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        [$route, $params] = $this->resolve($request);
        foreach ($params as $k => $v) {
            $request = $request->withAttribute($k, $v);
        }
        $request = $request->withAttribute('route', $route);
        $this->container->instance(ServerRequestInterface::class, $request);

        $core = new class ($this->container, $route, $params) implements RequestHandlerInterface {
            public function __construct(private Container $c, private Route $route, private array $params)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->c->instance(ServerRequestInterface::class, $request);
                $action = $this->route->action;
                try {
                    return ResponseFactory::from($this->c->call($action, $this->params));
                } catch (\Throwable $e) {
                    // Render here, inside route middleware, so session flashes etc. are persisted.
                    return $this->c->get(\Naluz\Foundation\ExceptionHandler::class)->render($e, $request);
                }
            }
        };

        return (new Pipeline($this->container, $this->aliases))->through($this->expand($route->middleware))->then($core)->handle($request);
    }

    public function url(string $name, array $params = []): string
    {
        $this->indexNames();
        if (!isset($this->named[$name])) {
            throw new \InvalidArgumentException("Route [{$name}] is not defined.");
        }
        return $this->named[$name]->url($params);
    }

    /** @return list<Route> */
    public function all(): array
    {
        $seen = [];
        foreach ($this->routes as $routes) {
            foreach ($routes as $route) {
                $seen[spl_object_id($route)] = $route;
            }
        }
        return array_values($seen);
    }

    private function indexNames(): void
    {
        $this->named = [];
        foreach ($this->all() as $route) {
            if ($route->name !== null) {
                $this->named[$route->groupName . $route->name] = $route;
            }
        }
    }
}
