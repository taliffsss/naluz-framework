<?php

declare(strict_types=1);

namespace Naluz\Security;

use Naluz\Session\Store;

/** Synchroniser-token CSRF protection backed by the session. */
final class Csrf
{
    public function __construct(private readonly Store $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get('_csrf');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->put('_csrf', $token);
        }
        return $token;
    }

    public function validate(?string $given): bool
    {
        $token = $this->session->get('_csrf');
        return is_string($token) && $token !== '' && is_string($given) && hash_equals($token, $given);
    }

    public function regenerate(): void
    {
        $this->session->forget('_csrf');
    }
}
