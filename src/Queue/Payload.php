<?php

declare(strict_types=1);

namespace Naluz\Queue;

use Naluz\Security\DecryptException;
use Naluz\Security\Encrypter;

/**
 * Wire format of a queued job: authenticated-encrypted JSON `{class, data}`.
 * Anyone able to write to the queue store (database, Redis) but without APP_KEY can neither read nor forge jobs,
 * and a decoded class must be a Job subclass.
 */
final class Payload
{
    public function __construct(private readonly Encrypter $encrypter)
    {
    }

    public function encode(Job $job): string
    {
        return $this->encrypter->encrypt(['class' => $job::class, 'data' => $job->payload(), 'id' => bin2hex(random_bytes(8))]);
    }

    public function decode(string $payload): Job
    {
        try {
            $envelope = $this->encrypter->decrypt($payload);
        } catch (DecryptException | \JsonException $e) {
            throw new InvalidPayloadException('Queue payload is corrupt or was not created by this application.', 0, $e);
        }
        $class = $envelope['class'] ?? null;
        if (!is_string($class) || !is_subclass_of($class, Job::class) || !is_array($envelope['data'] ?? null)) {
            throw new InvalidPayloadException('Queue payload does not describe a Job.');
        }
        return $class::fromPayload($envelope['data']);
    }
}
