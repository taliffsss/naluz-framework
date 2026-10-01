<?php

declare(strict_types=1);

namespace Naluz\Routing;

final class Route
{
    /** @var list<string> */
    public array $middleware = [];
    /** @var array<string,string> */
    public array $wheres = [];
    public ?string $name = null;
    public string $groupName = '';
    private ?string $regex = null;

    /**
     * @param list<string> $methods
     */
    public function __construct(
        public readonly array $methods,
        public string $uri,
        public readonly mixed $action,
    ) {
        $this->uri = '/' . trim($uri, '/');
    }

    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /** @param string|list<string> $middleware */
    public function middleware(string|array $middleware): self
    {
        array_push($this->middleware, ...(array) $middleware);
        return $this;
    }

    public function where(string $param, string $pattern): self
    {
        $this->wheres[$param] = $pattern;
        $this->regex = null;
        return $this;
    }

    public function regex(): string
    {
        return $this->regex ??= $this->compile();
    }

    private function compile(): string
    {
        $pattern = preg_replace_callback(
            '#/\{(\w+)(\?)?(?::([^}]+))?\}#',
            function (array $m): string {
                $pattern = $this->wheres[$m[1]] ?? ($m[3] ?? '[^/]+');
                $group = '(?P<' . $m[1] . '>' . $pattern . ')';
                return empty($m[2]) ? '/' . $group : '(?:/' . $group . ')?';
            },
            $this->uri === '/' ? '' : $this->uri
        );
        return '#^' . ($pattern === '' ? '/' : $pattern) . '$#D';
    }

    /** @return array<string,string>|null */
    public function match(string $path): ?array
    {
        if (!preg_match($this->regex(), $path, $m)) {
            return null;
        }
        return array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
    }

    /** @param array<string,scalar> $params */
    public function url(array $params = []): string
    {
        $query = $params;
        $uri = preg_replace_callback('#/\{(\w+)(\?)?(?::[^}]+)?\}#', function (array $m) use ($params, &$query): string {
            if (isset($params[$m[1]])) {
                unset($query[$m[1]]);
                return '/' . rawurlencode((string) $params[$m[1]]);
            }
            if (!empty($m[2])) {
                return '';
            }
            throw new \InvalidArgumentException("Missing parameter [{$m[1]}] for route [{$this->name}].");
        }, $this->uri) ?? $this->uri;

        return ($uri === '' ? '/' : $uri) . ($query ? '?' . http_build_query($query) : '');
    }
}
