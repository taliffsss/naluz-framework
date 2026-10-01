<?php

declare(strict_types=1);

namespace Naluz\Session;

use Naluz\Support\Arr;

/** Session data (JSON-serialised, so a tampered store can never trigger object injection). */
final class Store
{
    private string $id = '';
    private array $data = ['_flash_old' => [], '_flash_new' => []];
    private bool $started = false;

    public function __construct(private readonly \SessionHandlerInterface $handler)
    {
    }

    public static function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[a-f0-9]{40}$/', $id);
    }

    public static function newId(): string
    {
        return bin2hex(random_bytes(20));
    }

    /**
     * Load the session. Unknown ids supplied by the client are discarded (prevents session fixation).
     */
    public function start(?string $id = null): void
    {
        $raw = '';
        if ($id !== null && self::isValidId($id)) {
            $raw = (string) $this->handler->read($id);
        }
        if ($raw === '') {
            $this->id = self::newId();
            $this->data = [];
        } else {
            $this->id = $id;
            $decoded = json_decode($raw, true);
            $this->data = is_array($decoded) ? $decoded : [];
        }
        $this->data['_flash_old'] = $this->data['_flash_new'] ?? [];
        $this->data['_flash_new'] = [];
        $this->started = true;
    }

    public function save(): void
    {
        if ($this->started) {
            $this->handler->write($this->id, json_encode($this->data, JSON_THROW_ON_ERROR));
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->data, $key, fn () => Arr::get($this->data['_flash_old'], $key, $default));
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function put(string $key, mixed $value): void
    {
        Arr::set($this->data, $key, $value);
    }

    public function forget(string $key): void
    {
        Arr::forget($this->data, $key);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);
        return $value;
    }

    /** Available on this request and the next one only. */
    public function flash(string $key, mixed $value): void
    {
        $this->data['_flash_new'][$key] = $value;
    }

    public function all(): array
    {
        return Arr::except($this->data, ['_flash_old', '_flash_new']);
    }

    /** Issue a new id (call on login/privilege change). Old id is destroyed. */
    public function regenerate(): void
    {
        $this->handler->destroy($this->id);
        $this->id = self::newId();
    }

    public function invalidate(): void
    {
        $this->handler->destroy($this->id);
        $this->data = ['_flash_old' => [], '_flash_new' => []];
        $this->id = self::newId();
    }
}
