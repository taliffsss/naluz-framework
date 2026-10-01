<?php

declare(strict_types=1);

namespace Naluz\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Helpers for reading input from a PSR-7 server request.
 * The request itself stays a plain PSR-7 object so any PSR-15 middleware works.
 */
final class Request
{
    public static function capture(): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        return (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();
    }

    public static function create(string $method, string $uri, array $body = [], array $headers = [], array $server = []): ServerRequestInterface
    {
        $request = new ServerRequest($method, $uri, $headers, null, '1.1', $server);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);
        if ($body !== []) {
            $request = $request->withParsedBody($body);
        }
        return $request;
    }

    /** Decoded JSON body, form body, or query — merged (body wins). */
    public static function input(ServerRequestInterface $request): array
    {
        return array_replace($request->getQueryParams(), self::body($request));
    }

    public static function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed)) {
            return $parsed;
        }
        if (self::wantsJsonBody($request)) {
            $raw = (string) $request->getBody();
            if ($raw === '') {
                return [];
            }
            try {
                $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new HttpException(400, 'Malformed JSON body.');
            }
            return is_array($data) ? $data : [];
        }
        return [];
    }

    public static function wantsJsonBody(ServerRequestInterface $request): bool
    {
        return str_contains(strtolower($request->getHeaderLine('Content-Type')), 'json');
    }

    public static function expectsJson(ServerRequestInterface $request): bool
    {
        return str_contains($request->getHeaderLine('Accept'), 'json')
            || self::wantsJsonBody($request)
            || str_starts_with($request->getUri()->getPath(), '/api/')
            || $request->getUri()->getPath() === '/api'
            || strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';
    }

    public static function ip(ServerRequestInterface $request): string
    {
        return (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public static function bearerToken(ServerRequestInterface $request): ?string
    {
        if (preg_match('/^Bearer\s+(\S+)$/i', $request->getHeaderLine('Authorization'), $m)) {
            return $m[1];
        }
        return null;
    }
}
