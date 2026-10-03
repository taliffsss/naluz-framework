<?php

declare(strict_types=1);

namespace Naluz\Messaging;

/**
 * Wire format. Unsigned: a plain JSON envelope `{id, type, topic, timestamp, headers, payload}`, easy for services
 * in other languages. With a signing key: `{"v":1,"data":"<that json>","sig":"<hmac-sha256>"}`, and unsigned or
 * tampered messages are rejected, so only holders of the key can publish events you will act on.
 * Messages are only ever parsed as JSON (never unserialised) and are size- and depth-limited.
 */
final class Codec
{
    /** @param list<string> $previousKeys still accepted when verifying (key rotation) */
    public function __construct(
        private readonly string $signingKey = '',
        private readonly array $previousKeys = [],
        private readonly int $maxBytes = 1_048_576,
        private readonly int $maxDepth = 32,
    ) {
    }

    public function signs(): bool
    {
        return $this->signingKey !== '';
    }

    public function encode(Message $message): string
    {
        $data = json_encode([
            'id' => $message->id,
            'type' => $message->type,
            'topic' => $message->topic,
            'timestamp' => $message->timestamp,
            'headers' => (object) $message->headers,
            'payload' => (object) $message->payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $body = $this->signingKey === ''
            ? $data
            : json_encode(['v' => 1, 'data' => $data, 'sig' => hash_hmac('sha256', $data, $this->signingKey)], JSON_THROW_ON_ERROR);
        if (strlen($body) > $this->maxBytes) {
            throw new MessagingException("Message is {strlen($body)} bytes; the limit is {$this->maxBytes}.");
        }
        return $body;
    }

    /** @throws InvalidMessageException */
    public function decode(string $body, string $topic): Message
    {
        if (strlen($body) > $this->maxBytes) {
            throw new InvalidMessageException("Message is {strlen($body)} bytes; the limit is {$this->maxBytes}.");
        }
        $outer = $this->json($body);
        if ($this->signingKey !== '') {
            $data = $outer['data'] ?? null;
            $sig = $outer['sig'] ?? null;
            if (!is_string($data) || !is_string($sig) || !$this->verified($data, $sig)) {
                throw new InvalidMessageException('Message signature is missing or invalid.');
            }
            $outer = $this->json($data);
        }
        $headers = [];
        foreach (is_array($outer['headers'] ?? null) ? $outer['headers'] : [] as $k => $v) {
            if (is_scalar($v)) {
                $headers[(string) $k] = (string) $v;
            }
        }
        // interop: a producer that sent bare JSON (no envelope) has its whole object treated as the payload
        $payload = array_key_exists('payload', $outer) ? $outer['payload'] : $outer;
        return new Message(
            is_string($outer['id'] ?? null) && $outer['id'] !== '' ? $outer['id'] : bin2hex(random_bytes(8)),
            $topic,
            is_string($outer['type'] ?? null) ? $outer['type'] : $topic,
            is_array($payload) ? $payload : ['value' => $payload],
            $headers,
            is_int($outer['timestamp'] ?? null) ? $outer['timestamp'] : 0,
        );
    }

    private function verified(string $data, string $sig): bool
    {
        foreach ([$this->signingKey, ...$this->previousKeys] as $key) {
            if ($key !== '' && hash_equals(hash_hmac('sha256', $data, $key), $sig)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<mixed> */
    private function json(string $json): array
    {
        try {
            $value = json_decode($json, true, $this->maxDepth, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidMessageException('Message is not valid JSON: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($value)) {
            throw new InvalidMessageException('Message must be a JSON object.');
        }
        return $value;
    }
}
