<?php

declare(strict_types=1);

namespace Naluz\Http\Client;

use Psr\Http\Client\ClientInterface;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Convenience wrapper over ANY PSR-18 client (Guzzle by default):
 *
 *   $res = $http->get('https://api.example.com/users', ['page' => 2], ['Authorization' => 'Bearer …']);
 *   $data = Http::json($res);
 *   $http->post('https://api.example.com/users', ['name' => 'Ann']);   // JSON body
 */
final class Http
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly int $maxRedirects = 3,
    ) {
    }

    /** @param array<string,scalar|array> $query @param array<string,string> $headers */
    public function get(string $url, array $query = [], array $headers = []): ResponseInterface
    {
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        return $this->send('GET', $url, null, $headers);
    }

    /** @param array<string,mixed>|string $body arrays are sent as JSON */
    public function post(string $url, array|string $body = [], array $headers = []): ResponseInterface
    {
        return $this->send('POST', $url, $body, $headers);
    }

    public function put(string $url, array|string $body = [], array $headers = []): ResponseInterface
    {
        return $this->send('PUT', $url, $body, $headers);
    }

    public function patch(string $url, array|string $body = [], array $headers = []): ResponseInterface
    {
        return $this->send('PATCH', $url, $body, $headers);
    }

    public function delete(string $url, array $headers = []): ResponseInterface
    {
        return $this->send('DELETE', $url, null, $headers);
    }

    /** @throws \Psr\Http\Client\ClientExceptionInterface on network failure (HTTP error statuses are normal responses) */
    public function send(string $method, string $url, array|string|null $body = null, array $headers = []): ResponseInterface
    {
        $request = $this->requests->createRequest($method, $url);
        foreach ($headers as $name => $value) {
            if (preg_match('/[\r\n]/', $name . $value)) {
                throw new \InvalidArgumentException('Illegal line break in header.');
            }
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            if (is_array($body)) {
                $body = json_encode($body, JSON_THROW_ON_ERROR);
                $request = $request->withHeader('Content-Type', 'application/json');
            }
            $request = $request->withBody($this->streams->createStream($body));
        }
        return $this->follow($request);
    }

    /**
     * PSR-18 clients do not follow redirects, so it is done here — bounded, with every hop going through the client
     * (and therefore through the SSRF guard). Credentials are never forwarded to a different origin.
     */
    private function follow(RequestInterface $request): ResponseInterface
    {
        for ($hop = 0;; $hop++) {
            $response = $this->client->sendRequest($request);
            $status = $response->getStatusCode();
            $location = $response->getHeaderLine('Location');
            if (!in_array($status, [301, 302, 303, 307, 308], true) || $location === '' || $this->maxRedirects <= 0) {
                return $response;
            }
            if ($hop >= $this->maxRedirects) {
                throw new TooManyRedirectsException("Gave up after {$this->maxRedirects} redirects.", $request);
            }
            $target = UriResolver::resolve($request->getUri(), Utils::uriFor($location));
            $sameOrigin = $target->getScheme() === $request->getUri()->getScheme()
                && $target->getHost() === $request->getUri()->getHost()
                && ($target->getPort() ?? ($target->getScheme() === 'https' ? 443 : 80)) === ($request->getUri()->getPort() ?? ($request->getUri()->getScheme() === 'https' ? 443 : 80));

            $next = $request->withUri($target);
            if (!$sameOrigin) {
                $next = $next->withoutHeader('Authorization')->withoutHeader('Cookie')->withoutHeader('Proxy-Authorization');
            }
            // 303 always, and 301/302 for POST, become a body-less GET; 307/308 replay the request as-is
            if ($status === 303 || (in_array($status, [301, 302], true) && $request->getMethod() === 'POST')) {
                $next = $next->withMethod('GET')->withBody($this->streams->createStream(''))->withoutHeader('Content-Type')->withoutHeader('Content-Length');
            }
            $request = $next;
        }
    }

    /** Decode a JSON response body; throws on invalid JSON. */
    public static function json(ResponseInterface $response): mixed
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
