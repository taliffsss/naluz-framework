<?php

declare(strict_types=1);

namespace Naluz\Security;

/**
 * Authenticated encryption (XChaCha20-Poly1305 via libsodium). Tampering is detected.
 * Supports key rotation: pass retired keys in `$previousKeys`; they are tried for decryption only.
 */
final class Encrypter
{
    /** @var list<string> */
    private array $keys;

    /** @param list<string> $previousKeys */
    public function __construct(string $key, array $previousKeys = [])
    {
        $this->keys = array_map([self::class, 'parseKey'], [$key, ...$previousKeys]);
    }

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES));
    }

    private static function parseKey(string $key): string
    {
        $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new \InvalidArgumentException('APP_KEY must be 32 bytes. Run `php naluz key:generate`.');
        }
        return $raw;
    }

    public function encrypt(mixed $value): string
    {
        return $this->encryptString(json_encode($value, JSON_THROW_ON_ERROR));
    }

    public function decrypt(string $payload): mixed
    {
        return json_decode($this->decryptString($payload), true, 512, JSON_THROW_ON_ERROR);
    }

    public function encryptString(string $value): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($value, 'naluz', $nonce, $this->keys[0]);
        return rtrim(strtr(base64_encode($nonce . $cipher), '+/', '-_'), '=');
    }

    public function decryptString(string $payload): string
    {
        $raw = base64_decode(strtr($payload, '-_', '+/'), true);
        $nonceLen = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if ($raw === false || strlen($raw) <= $nonceLen) {
            throw new DecryptException('The payload is invalid.');
        }
        foreach ($this->keys as $key) {
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, $nonceLen), 'naluz', substr($raw, 0, $nonceLen), $key);
            if ($plain !== false) {
                return $plain;
            }
        }
        throw new DecryptException('The payload could not be decrypted (wrong key or tampered data).');
    }
}
