<?php

declare(strict_types=1);

namespace Naluz\Queue;

use Naluz\Container\Container;
use Psr\Log\LoggerInterface;

/** Pulls jobs off a queue and runs them, applying retry / backoff / failure rules. */
final class Worker
{
    private bool $stop = false;

    public function __construct(
        private readonly Container $container,
        private readonly QueueManager $queues,
        private readonly Payload $payloads,
        private readonly FailedJobs $failed,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function stop(): void
    {
        $this->stop = true;
    }

    /** @return string|null "processed" | "released" | "failed" or null when the queue was empty */
    public function runNextJob(string $queue = 'default', ?string $connection = null): ?string
    {
        $job = $this->queues->connection($connection)->pop($queue);
        return $job === null ? null : $this->process($job);
    }

    public function process(QueuedJob $queued): string
    {
        try {
            $job = $this->payloads->decode($queued->payload);
        } catch (InvalidPayloadException $e) {
            // can never succeed: don't retry, keep the evidence
            $this->failed->log($queued->queue, $queued->payload, $e);
            $queued->delete();
            $this->logger->error('Dropped invalid queue payload: {m}', ['m' => $e->getMessage()]);
            return 'failed';
        }

        try {
            $this->container->call([$job, 'handle']);
            $queued->delete();
            return 'processed';
        } catch (\Throwable $e) {
            if ($queued->attempts >= $job->tries()) {
                $this->failed->log($queued->queue, $queued->payload, $e);
                $queued->delete();
                $this->logger->error('Job {job} failed after {n} attempts: {m}', ['job' => $job::class, 'n' => $queued->attempts, 'm' => $e->getMessage(), 'exception' => $e]);
                try {
                    $job->failed($e);
                } catch (\Throwable) {
                    // a broken failed() hook must not kill the worker
                }
                return 'failed';
            }
            $queued->release($job->backoffFor($queued->attempts));
            return 'released';
        }
    }

    /**
     * @param int $maxJobs 0 = unlimited
     * @return int number of jobs handled
     */
    public function daemon(string $queue = 'default', int $sleep = 3, int $maxJobs = 0, bool $stopWhenEmpty = false, ?string $connection = null, int $memoryMb = 128): int
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
            $result = $this->runNextJob($queue, $connection);
            if ($result === null) {
                if ($stopWhenEmpty) {
                    break;
                }
                sleep(max(1, $sleep));
                continue;
            }
            $handled++;
            if (($maxJobs > 0 && $handled >= $maxJobs) || memory_get_usage(true) > $memoryMb * 1024 * 1024) {
                break;
            }
        }
        return $handled;
    }
}
