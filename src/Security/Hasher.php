<?php

declare(strict_types=1);

namespace Naluz\Security;

/** Password hashing: Argon2id when available, bcrypt otherwise. Transparent rehash support. */
final class Hasher
{
    private const MAX_LENGTH = 4096; // avoid CPU-exhaustion via giant "passwords"

    private readonly string|int $algo;

    public function __construct(private readonly array $options = [])
    {
        $this->algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    public function make(string $value): string
    {
        $this->guard($value);
        return password_hash($value, $this->algo, $this->options);
    }

    public function check(string $value, ?string $hash): bool
    {
        if ($hash === null || $hash === '' || strlen($value) > self::MAX_LENGTH) {
            return false;
        }
        return password_verify($value, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algo, $this->options);
    }

    private function guard(string $value): void
    {
        if (strlen($value) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('Value is too long to hash.');
        }
    }
}
