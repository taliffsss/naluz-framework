<?php

declare(strict_types=1);

namespace Naluz\Http\Middleware;

use Naluz\Cache\Incrementable;
use Naluz\Http\Request;
use Naluz\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\SimpleCache\CacheInterface;

/** Fixed-window rate limiter. Usage: `throttle:60,1` = 60 requests per 1 minute per client IP + path. */
final class Throttle implements MiddlewareInterface
{
    public function __construct(private readonly CacheInterface $cache, private readonly array $parameters = [])
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->cache instanceof Incrementable) {
            return $handler->handle($request);
        }
        $max = max(1, (int) ($this->parameters[0] ?? 60));
        $window = max(1, (int) ($this->parameters[1] ?? 1)) * 60;
        $key = 'throttle_' . sha1(Request::ip($request) . '|' . $request->getUri()->getPath());

        $hits = $this->cache->increment($key, $window);
        $remaining = max(0, $max - $hits);
        $headers = ['X-RateLimit-Limit' => (string) $max, 'X-RateLimit-Remaining' => (string) $remaining];

        if ($hits > $max) {
            $headers['Retry-After'] = (string) $window;
            $body = ['message' => 'Too Many Requests'];
            return Request::expectsJson($request)
                ? Response::json($body, 429, $headers)
                : new Response(429, $headers + ['Content-Type' => 'text/plain'], 'Too Many Requests');
        }
        $response = $handler->handle($request);
        foreach ($headers as $k => $v) {
            $response = $response->withHeader($k, $v);
        }
        return $response;
    }
}
