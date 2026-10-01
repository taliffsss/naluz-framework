<?php

declare(strict_types=1);

namespace Naluz\Queue;

/**
 * Base class for queued work. Public properties are the job's data and are serialised as JSON — never PHP
 * `serialize()` — so queue payloads can't be abused for object injection. Dependencies are injected into handle().
 *
 *   final class SendWelcome extends Job {
 *       public function __construct(public readonly int $userId) {}
 *       public function handle(Mailer $mailer): void { ... }
 *   }
 *   SendWelcome::dispatch($user->id);
 *   (new SendWelcome($id))->onQueue('mail')->delay(60)->dispatchNow() / app(QueueManager::class)->dispatch($job)
 */
abstract class Job
{
    protected int $tries = 3;
    /** @var int|list<int> seconds to wait before retrying; a list gives per-attempt backoff */
    protected int|array $backoff = 0;
    protected string $queueName = 'default';
    protected int $delaySeconds = 0;

    /** Called once after the final failed attempt. */
    public function failed(\Throwable $e): void
    {
    }

    public function tries(): int
    {
        return $this->tries;
    }

    public function backoffFor(int $attempt): int
    {
        $b = $this->backoff;
        return is_array($b) ? (int) ($b[min($attempt, count($b)) - 1] ?? 0) : $b;
    }

    public function onQueue(string $queue): static
    {
        $this->queueName = $queue;
        return $this;
    }

    public function delay(int $seconds): static
    {
        $this->delaySeconds = max(0, $seconds);
        return $this;
    }

    public function queueName(): string
    {
        return $this->queueName;
    }

    public function delaySeconds(): int
    {
        return $this->delaySeconds;
    }

    /** Create and dispatch in one call: `SendWelcome::dispatch($userId)`. */
    public static function dispatch(mixed ...$args): static
    {
        $job = new static(...$args);
        app(QueueManager::class)->dispatch($job);
        return $job;
    }

    /** @return array<string,mixed> the job's public data properties */
    public function payload(): array
    {
        $data = [];
        foreach ((new \ReflectionObject($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
            if (!$p->isStatic() && $p->isInitialized($this)) {
                $data[$p->getName()] = $p->getValue($this);
            }
        }
        return $data;
    }

    /** @param array<string,mixed> $data */
    public static function fromPayload(array $data): static
    {
        $ref = new \ReflectionClass(static::class);
        $job = $ref->newInstanceWithoutConstructor();
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
            if (!$p->isStatic() && array_key_exists($p->getName(), $data)) {
                $p->setValue($job, $data[$p->getName()]);
            }
        }
        return $job;
    }
}
