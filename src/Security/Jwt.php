<?php

declare(strict_types=1);

namespace Naluz\Security;

/**
 * Minimal HS256 JSON Web Token implementation for stateless API auth.
 * Algorithm is pinned (the `alg` header is never trusted), signatures use hash_equals,
 * and `exp` / `nbf` / `iss` are validated.
 */
final class Jwt
{
    public function __construct(private readonly string $secret, private readonly ?string $issuer = null, private readonly int $leeway = 10)
    {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('JWT secret must be at least 32 bytes.');
        }
    }

    public function encode(array $claims, int $ttl = 3600): string
    {
        $now = time();
        $claims += ['iat' => $now, 'nbf' => $now, 'exp' => $now + $ttl];
        if ($this->issuer !== null) {
            $claims += ['iss' => $this->issuer];
        }
        $head = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'HS256'], JSON_THROW_ON_ERROR));
        $body = self::b64(json_encode($claims, JSON_THROW_ON_ERROR));
        return "{$head}.{$body}." . self::b64(hash_hmac('sha256', "{$head}.{$body}", $this->secret, true));
    }

    /** @throws InvalidTokenException */
    public function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new InvalidTokenException('Malformed token.');
        }
        [$head, $body, $sig] = $parts;
        $header = json_decode((string) self::unb64($head), true);
        if (!is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
            throw new InvalidTokenException('Unsupported algorithm.');
        }
        $expected = self::b64(hash_hmac('sha256', "{$head}.{$body}", $this->secret, true));
        if (!hash_equals($expected, $sig)) {
            throw new InvalidTokenException('Invalid signature.');
        }
        $claims = json_decode((string) self::unb64($body), true);
        if (!is_array($claims)) {
            throw new InvalidTokenException('Malformed claims.');
        }
        $now = time();
        if (!isset($claims['exp']) || $claims['exp'] + $this->leeway < $now) {
            throw new InvalidTokenException('Token expired.');
        }
        if (isset($claims['nbf']) && $claims['nbf'] - $this->leeway > $now) {
            throw new InvalidTokenException('Token not yet valid.');
        }
        if ($this->issuer !== null && ($claims['iss'] ?? null) !== $this->issuer) {
            throw new InvalidTokenException('Wrong issuer.');
        }
        return $claims;
    }

    private static function b64(string $v): string
    {
        return rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
    }

    private static function unb64(string $v): string|false
    {
        return base64_decode(strtr($v, '-_', '+/'), true);
    }
}
