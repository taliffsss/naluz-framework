<?php

declare(strict_types=1);

namespace Naluz\Auth;

use Naluz\Config\Repository;
use Naluz\Database\Orm\Model;
use Naluz\Security\Hasher;
use Naluz\Session\Store;

/** Session-based authentication against the model in `config/auth.php`. */
final class Auth
{
    private ?Model $user = null;
    private bool $resolved = false;
    private ?string $dummyHash = null;

    public function __construct(private readonly Store $session, private readonly Hasher $hasher, private readonly Repository $config)
    {
    }

    /** @param array<string,mixed> $credentials e.g. ['email' => ..., 'password' => ...] */
    public function attempt(array $credentials): bool
    {
        $username = (string) $this->config->get('auth.username', 'email');
        $passwordColumn = (string) $this->config->get('auth.password', 'password');
        $password = (string) ($credentials[$passwordColumn] ?? '');
        $identifier = $credentials[$username] ?? null;

        /** @var class-string<Model> $model */
        $model = $this->config->get('auth.model') ?? throw new \LogicException('Set auth.model in config/auth.php.');
        $user = is_string($identifier) && $identifier !== '' ? $model::query()->where($username, '=', $identifier)->first() : null;

        // Always spend hashing time, so response timing does not reveal which emails exist.
        $hash = $user?->getAttribute($passwordColumn) ?? ($this->dummyHash ??= $this->hasher->make('naluz-dummy'));
        $ok = $this->hasher->check($password, (string) $hash) && $user !== null;

        if ($ok) {
            if ($this->hasher->needsRehash((string) $hash)) {
                $user->forceFill([$passwordColumn => $this->hasher->make($password)])->save();
            }
            $this->login($user);
        }
        return $ok;
    }

    public function login(Model $user): void
    {
        $this->session->regenerate(); // prevents session fixation
        $this->session->put('_auth_id', $user->getKey());
        $this->user = $user;
        $this->resolved = true;
    }

    public function logout(): void
    {
        $this->session->invalidate();
        $this->user = null;
        $this->resolved = true;
    }

    public function id(): int|string|null
    {
        return $this->session->get('_auth_id');
    }

    public function user(): ?Model
    {
        if (!$this->resolved) {
            $this->resolved = true;
            $id = $this->id();
            $model = $this->config->get('auth.model');
            $this->user = $id !== null && $model ? $model::find($id) : null;
        }
        return $this->user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return !$this->check();
    }
}
