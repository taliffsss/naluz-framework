<?php

declare(strict_types=1);

namespace Naluz\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Lets HTML forms send PUT/PATCH/DELETE via a POST with a `_method` field. */
final class MethodOverride implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() === 'POST') {
            $body = $request->getParsedBody();
            $method = is_array($body) ? strtoupper((string) ($body['_method'] ?? '')) : '';
            if (in_array($method, ['PUT', 'PATCH', 'DELETE'], true)) {
                $request = $request->withMethod($method);
            }
        }
        return $handler->handle($request);
    }
}
