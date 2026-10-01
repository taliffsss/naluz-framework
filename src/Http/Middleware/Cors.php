<?php

declare(strict_types=1);

namespace Naluz\Http\Middleware;

use Naluz\Config\Repository;
use Naluz\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** CORS with an explicit origin allow-list (never reflects arbitrary origins). */
final class Cors implements MiddlewareInterface
{
    public function __construct(private readonly Repository $config)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        $cfg = (array) $this->config->get('security.cors', []);
        $allowed = (array) ($cfg['allowed_origins'] ?? []);

        if ($origin === '' || $allowed === []) {
            return $handler->handle($request);
        }
        $any = in_array('*', $allowed, true);
        $credentials = (bool) ($cfg['supports_credentials'] ?? false);
        if (!$any && !in_array($origin, $allowed, true)) {
            return $request->getMethod() === 'OPTIONS' ? new Response(403) : $handler->handle($request);
        }

        $allowOrigin = ($any && !$credentials) ? '*' : $origin; // wildcard + credentials is forbidden by spec
        $headers = ['Access-Control-Allow-Origin' => $allowOrigin, 'Vary' => 'Origin'];
        if ($credentials) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        if ($request->getMethod() === 'OPTIONS' && $request->hasHeader('Access-Control-Request-Method')) {
            $headers += [
                'Access-Control-Allow-Methods' => implode(', ', $cfg['allowed_methods'] ?? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']),
                'Access-Control-Allow-Headers' => implode(', ', $cfg['allowed_headers'] ?? ['Content-Type', 'Authorization', 'X-Requested-With']),
                'Access-Control-Max-Age' => (string) ($cfg['max_age'] ?? 600),
            ];
            return new Response(204, $headers);
        }

        $response = $handler->handle($request);
        foreach ($headers as $k => $v) {
            $response = $response->withHeader($k, $v);
        }
        if (!empty($cfg['exposed_headers'])) {
            $response = $response->withHeader('Access-Control-Expose-Headers', implode(', ', $cfg['exposed_headers']));
        }
        return $response;
    }
}
