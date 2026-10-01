<?php

declare(strict_types=1);

namespace Naluz\Http\Client;

use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\RequestInterface;

/**
 * SSRF protection for outgoing requests: refuses loopback, private (RFC 1918 / ULA), link-local (including the cloud
 * metadata address 169.254.169.254) and reserved addresses, and non-HTTP(S) schemes. Runs for every hop, redirects included.
 *
 * Limitation: DNS is resolved here and again by the HTTP handler, so a hostile DNS server could answer differently the second
 * time (DNS rebinding). For high-risk fetches of user-supplied URLs, also restrict egress at the network level.
 */
final class PrivateNetworkGuard
{
    public function __invoke(RequestInterface $request): RequestInterface
    {
        $uri = $request->getUri();
        if (!in_array(strtolower($uri->getScheme()), ['http', 'https'], true)) {
            throw new RequestException('Blocked request: only http(s) is allowed.', $request);
        }
        $host = trim($uri->getHost(), '[]');
        if ($host === '') {
            throw new RequestException('Blocked request: missing host.', $request);
        }
        foreach ($this->resolve($host) as $ip) {
            if (!self::isPublic($ip)) {
                throw new RequestException('Blocked request: the target resolves to a private or reserved address.', $request);
            }
        }
        return $request;
    }

    /** @return list<string> */
    private function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $ips = gethostbynamel($host) ?: [];
        $aaaa = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($aaaa as $r) {
            $ips[] = $r['ipv6'];
        }
        if ($ips === []) {
            throw new RequestException("Blocked request: cannot resolve host [{$host}].", new \GuzzleHttp\Psr7\Request('GET', 'http://invalid'));
        }
        return $ips;
    }

    public static function isPublic(string $ip): bool
    {
        // IPv4-mapped IPv6 (::ffff:127.0.0.1) must be judged by the embedded v4 address
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            $ip = $m[1];
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && self::inV4Blocklist($ip)) {
            return false;
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && !str_starts_with(strtolower($ip), 'fe80:')   // link-local v6
            && $ip !== '::1' && $ip !== '::';
    }

    /** Ranges PHP's filter flags do not cover: carrier-grade NAT, IETF protocol assignments, benchmarking, documentation. */
    private static function inV4Blocklist(string $ip): bool
    {
        $n = ip2long($ip);
        foreach (['100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4'] as $cidr) {
            [$base, $bits] = explode('/', $cidr);
            $mask = -1 << (32 - (int) $bits);
            if (($n & $mask) === (ip2long($base) & $mask)) {
                return true;
            }
        }
        return false;
    }
}
