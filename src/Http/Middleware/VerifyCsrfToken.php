<?php

declare(strict_types=1);

namespace Naluz\Http\Middleware;

use Naluz\Http\HttpException;
use Naluz\Security\Csrf;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Rejects state-changing requests lacking a valid token (`_token` field or `X-CSRF-TOKEN` header).
 * Also rejects cross-origin requests by Origin header as defence in depth.
 */
final class VerifyCsrfToken implements MiddlewareInterface
{
    public function __construct(private readonly Csrf $csrf)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $handler->handle($request);
        }

        $origin = $request->getHeaderLine('Origin');
        if ($origin !== '' && strcasecmp((string) parse_url($origin, PHP_URL_HOST) . ':' . (parse_url($origin, PHP_URL_PORT) ?? ''), $this->hostPort($request)) !== 0) {
            throw new HttpException(419, 'Cross-origin request blocked.');
        }

        $body = $request->getParsedBody();
        $token = is_array($body) && isset($body['_token']) && is_string($body['_token'])
            ? $body['_token']
            : ($request->getHeaderLine('X-CSRF-TOKEN') ?: null);

        if (!$this->csrf->validate($token)) {
            throw new HttpException(419, 'CSRF token mismatch.');
        }
        return $handler->handle($request);
    }

    private function hostPort(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        return $uri->getHost() . ':' . ($uri->getPort() ?? '');
    }
}
