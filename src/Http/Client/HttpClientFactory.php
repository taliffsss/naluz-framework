<?php

declare(strict_types=1);

namespace Naluz\Http\Client;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Client\ClientInterface;

/** Builds the Guzzle-backed PSR-18 client with safe defaults. */
final class HttpClientFactory
{
    /**
     * @param array<string,mixed> $config timeout, connect_timeout, allow_private_networks, user_agent, handler
     */
    public static function make(array $config = []): ClientInterface
    {
        $stack = ($config['handler'] ?? null) instanceof HandlerStack ? $config['handler'] : HandlerStack::create($config['handler'] ?? null);
        if (empty($config['allow_private_networks'])) {
            // unshift = innermost: runs right before every actual network request, including each redirect hop
            $stack->unshift(Middleware::mapRequest(new PrivateNetworkGuard()), 'ssrf_guard');
        }
        return new Client([
            'handler' => $stack,
            'timeout' => (float) ($config['timeout'] ?? 10),
            'connect_timeout' => (float) ($config['connect_timeout'] ?? 5),
            'http_errors' => false,   // 4xx/5xx are responses, as PSR-18 requires; only transport failures throw
            'verify' => true,         // never disable TLS verification
            'headers' => ['User-Agent' => (string) ($config['user_agent'] ?? 'NaluzPHP')],
        ]);
    }
}
