<?php

declare(strict_types=1);

namespace Naluz\Http\Middleware;

use Naluz\Config\Repository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Adds hardened default headers; override or disable any of them in `config/security.php`. */
final class SecurityHeaders implements MiddlewareInterface
{
    private const DEFAULTS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Content-Security-Policy' => "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'",
    ];

    public function __construct(private readonly Repository $config)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $headers = array_replace(self::DEFAULTS, (array) $this->config->get('security.headers', []));

        if ($request->getUri()->getScheme() === 'https') {
            $headers += ['Strict-Transport-Security' => 'max-age=31536000; includeSubDomains'];
        }
        foreach ($headers as $name => $value) {
            if ($value !== null && $value !== false && !$response->hasHeader($name)) {
                $response = $response->withHeader($name, (string) $value);
            }
        }
        return $response;
    }
}
