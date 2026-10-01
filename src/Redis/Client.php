<?php

declare(strict_types=1);

namespace Naluz\Redis;

/**
 * Minimal, dependency-free Redis client (RESP2 over a stream). Lazy-connecting; reconnects once when the
 * connection drops. Supports every command via `command()` plus the handful of helpers the framework uses.
 */
class Client
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 6379,
        private readonly ?string $password = null,
        private readonly int $database = 0,
        private readonly float $timeout = 2.0,
        private readonly string $username = '',
    ) {
    }

    /** @param array<string,mixed> $config */
    public static function fromConfig(array $config): self
    {
        return new self(
            (string) ($config['host'] ?? '127.0.0.1'),
            (int) ($config['port'] ?? 6379),
            ($config['password'] ?? null) ?: null,
            (int) ($config['database'] ?? 0),
            (float) ($config['timeout'] ?? 2.0),
            (string) ($config['username'] ?? '')
        );
    }

    public function disconnect(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
    }

    private function connect(): void
    {
        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $error, $this->timeout);
        if ($socket === false) {
            throw new RedisException("Cannot connect to Redis at {$this->host}:{$this->port}: {$error}");
        }
        stream_set_timeout($socket, (int) ceil($this->timeout) + 5);
        $this->socket = $socket;
        if ($this->password !== null) {
            $this->rawCommand($this->username !== '' ? ['AUTH', $this->username, $this->password] : ['AUTH', $this->password]);
        }
        if ($this->database !== 0) {
            $this->rawCommand(['SELECT', (string) $this->database]);
        }
    }

    /** Run any Redis command: `$c->command('SET', 'k', 'v', 'EX', 60)`. */
    public function command(string|int|float ...$args): mixed
    {
        $args = array_map('strval', $args);
        for ($attempt = 0;; $attempt++) {
            try {
                if (!is_resource($this->socket)) {
                    $this->connect();
                }
                return $this->rawCommand($args);
            } catch (RedisException $e) {
                $this->disconnect();
                // Server-side errors (-ERR ...) are final; only transport failures are retried.
                if ($attempt > 0 || str_starts_with($e->getMessage(), 'Redis error:')) {
                    throw $e;
                }
            }
        }
    }

    /** @param list<string> $args */
    private function rawCommand(array $args): mixed
    {
        $payload = '*' . count($args) . "\r\n";
        foreach ($args as $a) {
            $payload .= '$' . strlen($a) . "\r\n" . $a . "\r\n";
        }
        $written = @fwrite($this->socket, $payload);
        if ($written === false || $written < strlen($payload)) {
            throw new RedisException('Connection lost while writing to Redis.');
        }
        return $this->read();
    }

    private function read(): mixed
    {
        $line = fgets($this->socket);
        if ($line === false) {
            throw new RedisException('Connection lost while reading from Redis.');
        }
        $type = $line[0];
        $body = substr($line, 1, -2);
        switch ($type) {
            case '+':
                return $body;
            case '-':
                throw new RedisException('Redis error: ' . $body);
            case ':':
                return (int) $body;
            case '$':
                $len = (int) $body;
                if ($len < 0) {
                    return null;
                }
                $data = '';
                while (strlen($data) < $len + 2) {
                    $chunk = fread($this->socket, $len + 2 - strlen($data));
                    if ($chunk === false || $chunk === '') {
                        throw new RedisException('Connection lost while reading from Redis.');
                    }
                    $data .= $chunk;
                }
                return substr($data, 0, $len);
            case '*':
                $n = (int) $body;
                if ($n < 0) {
                    return null;
                }
                $out = [];
                for ($i = 0; $i < $n; $i++) {
                    $out[] = $this->read();
                }
                return $out;
        }
        throw new RedisException('Unexpected Redis reply.');
    }

    // ---------------------------------------------------------------- conveniences

    public function get(string $key): ?string
    {
        return $this->command('GET', $key);
    }

    public function set(string $key, string $value, ?int $ttl = null): void
    {
        $ttl === null ? $this->command('SET', $key, $value) : $this->command('SET', $key, $value, 'EX', max(1, $ttl));
    }

    public function del(string ...$keys): int
    {
        return $keys === [] ? 0 : $this->command('DEL', ...$keys);
    }

    public function exists(string $key): bool
    {
        return $this->command('EXISTS', $key) > 0;
    }

    /** Atomic INCR that sets the expiry on first use (fixed-window counters). */
    public function incrWithTtl(string $key, int $ttl): int
    {
        return (int) $this->command(
            'EVAL',
            "local v = redis.call('INCR', KEYS[1]) if v == 1 then redis.call('EXPIRE', KEYS[1], ARGV[1]) end return v",
            1,
            $key,
            max(1, $ttl)
        );
    }

    /** Delete every key matching a glob pattern, using SCAN (never KEYS). */
    public function deletePattern(string $pattern): int
    {
        $cursor = '0';
        $n = 0;
        do {
            [$cursor, $keys] = $this->command('SCAN', $cursor, 'MATCH', $pattern, 'COUNT', 200);
            $n += $this->del(...$keys);
        } while ($cursor !== '0');
        return $n;
    }
}
