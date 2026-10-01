<?php

declare(strict_types=1);

namespace Naluz\Http\Middleware;

use Naluz\Config\Repository;
use Naluz\Container\Container;
use Naluz\Session\Store;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class StartSession implements MiddlewareInterface
{
    public function __construct(private readonly Store $session, private readonly Repository $config, private readonly Container $container)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $name = (string) $this->config->get('session.cookie', 'naluz_session');
        $this->session->start($request->getCookieParams()[$name] ?? null);
        $this->container->instance(Store::class, $this->session);

        try {
            $response = $handler->handle($request->withAttribute('session', $this->session));
        } finally {
            $this->session->save();
        }

        $lifetime = (int) $this->config->get('session.lifetime', 7200);
        $secure = $this->config->get('session.secure') ?? ($request->getUri()->getScheme() === 'https');
        $cookie = sprintf(
            '%s=%s; Path=/; Max-Age=%d; HttpOnly; SameSite=%s%s',
            $name,
            $this->session->id(),
            $lifetime,
            $this->config->get('session.same_site', 'Lax'),
            $secure ? '; Secure' : ''
        );
        return $response->withAddedHeader('Set-Cookie', $cookie);
    }
}
