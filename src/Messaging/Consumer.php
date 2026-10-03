<?php

declare(strict_types=1);

namespace Naluz\Messaging;

use Naluz\Config\Repository;
use Naluz\Container\Container;
use Psr\Log\LoggerInterface;

/**
 * Pulls messages for a consumer group and hands them to the subscribers registered in config/messaging.php.
 * A failing message is re-published for that group only (`tries` attempts), then moved to `<topic>.dlq`.
 * Delivery is at-least-once: subscribers must be idempotent.
 */
final class Consumer
{
    private bool $stop = false;

    public function __construct(
        private readonly Container $container,
        private readonly BrokerManager $brokers,
        private readonly EventBus $bus,
        private readonly Codec $codec,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function stop(): void
    {
        $this->stop = true;
    }

    /**
     * @param list<string> $topics
     * @return string|null "processed" | "retried" | "dead" | "skipped" | "invalid", or null when nothing arrived
     */
    public function consumeOne(array $topics, string $group, ?string $connection = null, int $timeoutMs = 1000, int $tries = 3, int $backoff = 0): ?string
    {
        $delivery = $this->brokers->connection($connection)->receive($topics, $group, $timeoutMs);
        return $delivery === null ? null : $this->handle($delivery, $group, $connection, $tries, $backoff);
    }

    public function handle(Delivery $delivery, string $group, ?string $connection = null, int $tries = 3, int $backoff = 0): string
    {
        try {
            $message = $this->codec->decode($delivery->body, $delivery->topic);
        } catch (InvalidMessageException $e) {
            $this->logger->error('Dropped invalid message on {topic}: {m}', ['topic' => $delivery->topic, 'm' => $e->getMessage()]);
            $delivery->ack();
            return 'invalid';
        }

        $target = $message->headers[Message::TARGET_GROUP] ?? null;
        if ($target !== null && $target !== '' && $target !== $group) {
            $delivery->ack(); // a retry meant for another group
            return 'skipped';
        }

        $subscribers = $this->subscribersFor($delivery->topic);
        if ($subscribers === []) {
            $this->logger->warning('No subscriber for topic {topic}; message {id} acknowledged unhandled.', ['topic' => $delivery->topic, 'id' => $message->id]);
            $delivery->ack();
            return 'skipped';
        }

        try {
            foreach ($subscribers as $class) {
                $subscriber = $this->container->make($class);
                if (!$subscriber instanceof Subscriber) {
                    throw new \LogicException("{$class} must implement " . Subscriber::class . '.');
                }
                $subscriber->handle($message);
            }
        } catch (\Throwable $e) {
            return $this->failed($delivery, $message, $group, $connection, $tries, $backoff, $e);
        }
        $delivery->ack();
        return 'processed';
    }

    private function failed(Delivery $delivery, Message $message, string $group, ?string $connection, int $tries, int $backoff, \Throwable $e): string
    {
        $attempts = $message->attempts();
        if ($attempts < max(1, $tries)) {
            if ($backoff > 0) {
                sleep(min(30, $backoff * $attempts));
            }
            $this->bus->send($message->withHeaders([Message::ATTEMPTS => (string) ($attempts + 1), Message::TARGET_GROUP => $group]), null, $connection);
            $delivery->ack();
            $this->logger->warning('Message {id} on {topic} failed (attempt {n}): {m}', ['id' => $message->id, 'topic' => $message->topic, 'n' => $attempts, 'm' => $e->getMessage()]);
            return 'retried';
        }
        $dead = $message->withTopic($message->topic . '.dlq')->withHeaders([
            'x-original-topic' => $message->topic,
            'x-failed-group' => $group,
            'x-error' => substr($e::class . ': ' . $e->getMessage(), 0, 500),
            Message::ATTEMPTS => (string) $attempts,
            Message::TARGET_GROUP => '', // a dead letter is for whoever inspects it, not for one group
        ]);
        $this->bus->send($dead, null, $connection);
        $delivery->ack();
        $this->logger->error('Message {id} on {topic} dead-lettered after {n} attempts: {m}', ['id' => $message->id, 'topic' => $message->topic, 'n' => $attempts, 'm' => $e->getMessage(), 'exception' => $e]);
        return 'dead';
    }

    /** @return list<class-string> */
    private function subscribersFor(string $topic): array
    {
        $found = [];
        foreach ((array) $this->config->get('messaging.subscribers', []) as $pattern => $classes) {
            if ((string) $pattern === $topic || (str_contains((string) $pattern, '*') && fnmatch((string) $pattern, $topic))) {
                array_push($found, ...array_values((array) $classes));
            }
        }
        return $found;
    }

    /**
     * @param list<string> $topics
     * @param int $maxMessages 0 = unlimited
     * @return int number of messages handled
     */
    public function daemon(array $topics, string $group, ?string $connection = null, int $tries = 3, int $backoff = 0, int $maxMessages = 0, bool $stopWhenEmpty = false, int $timeoutMs = 1000, int $memoryMb = 128): int
    {
        $this->stop = false;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT] as $sig) {
                pcntl_signal($sig, fn () => $this->stop());
            }
        }
        $handled = 0;
        while (!$this->stop) {
            $result = $this->consumeOne($topics, $group, $connection, $timeoutMs, $tries, $backoff);
            if ($result === null) {
                if ($stopWhenEmpty) {
                    break;
                }
                continue; // receive() already waited up to $timeoutMs
            }
            $handled++;
            if (($maxMessages > 0 && $handled >= $maxMessages) || memory_get_usage(true) > $memoryMb * 1024 * 1024) {
                break;
            }
        }
        return $handled;
    }
}
