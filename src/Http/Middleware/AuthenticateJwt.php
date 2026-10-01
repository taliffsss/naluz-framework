<?php

declare(strict_types=1);

namespace Naluz\Http\Middleware;

use Naluz\Http\HttpException;
use Naluz\Http\Request;
use Naluz\Security\InvalidTokenException;
use Naluz\Security\Jwt;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Stateless bearer-token guard. Exposes `auth.claims` and `auth.id` (the `sub` claim) request attributes. */
final class AuthenticateJwt implements MiddlewareInterface
{
    public function __construct(private readonly Jwt $jwt)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = Request::bearerToken($request) ?? throw new HttpException(401, 'Missing bearer token.', ['WWW-Authenticate' => 'Bearer']);
        try {
            $claims = $this->jwt->decode($token);
        } catch (InvalidTokenException) {
            throw new HttpException(401, 'Invalid or expired token.', ['WWW-Authenticate' => 'Bearer error="invalid_token"']);
        }
        return $handler->handle($request->withAttribute('auth.claims', $claims)->withAttribute('auth.id', $claims['sub'] ?? null));
    }
}
