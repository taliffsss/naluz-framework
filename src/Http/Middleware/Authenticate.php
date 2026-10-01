<?php

declare(strict_types=1);

namespace Naluz\Http\Middleware;

use Naluz\Auth\Auth;
use Naluz\Config\Repository;
use Naluz\Http\HttpException;
use Naluz\Http\Request;
use Naluz\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Session auth guard: 401 for JSON clients, redirect to the login page for browsers. */
final class Authenticate implements MiddlewareInterface
{
    public function __construct(private readonly Auth $auth, private readonly Repository $config)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->auth->check()) {
            return $handler->handle($request);
        }
        if (Request::expectsJson($request)) {
            throw new HttpException(401, 'Unauthenticated.');
        }
        return Response::redirect((string) $this->config->get('auth.login_path', '/login'));
    }
}
