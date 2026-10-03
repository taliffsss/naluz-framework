<?php

declare(strict_types=1);

namespace Naluz\Console\Commands;

use Naluz\Config\Repository;
use Naluz\Console\Command;
use Naluz\Console\Input;
use Naluz\Console\Output;
use Naluz\Foundation\Application;
use Naluz\Messaging\BrokerManager;
use Naluz\Messaging\Consumer;
use Naluz\Messaging\EventBus;
use Naluz\Messaging\MessagingException;
use Naluz\Support\Str;

/** messaging:consume, messaging:declare, messaging:publish */
final class MessagingCommands extends Command
{
    public function __construct(Application $app, private readonly string $kind)
    {
        parent::__construct($app);
    }

    public static function instances(Application $app): array
    {
        return array_map(fn ($k) => new self($app, $k), ['messaging:consume', 'messaging:declare', 'messaging:publish']);
    }

    public function name(): string
    {
        return $this->kind;
    }

    public function description(): string
    {
        return match ($this->kind) {
            'messaging:consume' => 'Consume events: messaging:consume <topic[,topic]> (--group= --connection= --tries=3 --backoff=0 --max-messages=N --stop-when-empty --memory=128)',
            'messaging:declare' => 'Create the consumer group / queue for topics up front: messaging:declare <topic[,topic]> (--group= --connection=)',
            default => 'Publish a test event: messaging:publish <topic> \'{"json":"payload"}\' (--connection=)',
        };
    }

    public function handle(Input $input, Output $output): int
    {
        $connection = is_string($input->option('connection')) ? $input->option('connection') : null;
        try {
            return match ($this->kind) {
                'messaging:consume' => $this->consume($input, $output, $connection),
                'messaging:declare' => $this->declare($input, $output, $connection),
                default => $this->publish($input, $output, $connection),
            };
        } catch (MessagingException | \InvalidArgumentException $e) {
            $output->error($e->getMessage());
            return 1;
        }
    }

    /** @return list<string> */
    private function topics(Input $input): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $input->argument(0, '')))));
    }

    private function group(Input $input): string
    {
        $config = $this->app->make(Repository::class);
        $group = $input->option('group');
        return is_string($group) && $group !== '' ? $group : (string) ($config->get('messaging.group') ?: Str::slug((string) $config->get('app.name', 'app')));
    }

    private function consume(Input $input, Output $output, ?string $connection): int
    {
        $topics = $this->topics($input);
        if ($topics === []) {
            $output->error('Usage: messaging:consume <topic[,topic]> [--group=name]');
            return 1;
        }
        $group = $this->group($input);
        $output->info('Consuming [' . implode(', ', $topics) . "] as group [{$group}] — Ctrl+C to stop");
        $n = $this->app->make(Consumer::class)->daemon(
            $topics,
            $group,
            $connection,
            (int) $input->option('tries', '3'),
            (int) $input->option('backoff', '0'),
            (int) $input->option('max-messages', '0'),
            (bool) $input->option('stop-when-empty'),
            1000,
            (int) $input->option('memory', '128')
        );
        $output->line("Handled {$n} message(s).");
        return 0;
    }

    private function declare(Input $input, Output $output, ?string $connection): int
    {
        $topics = $this->topics($input);
        if ($topics === []) {
            $output->error('Usage: messaging:declare <topic[,topic]> [--group=name]');
            return 1;
        }
        $group = $this->group($input);
        $this->app->make(BrokerManager::class)->connection($connection)->declare($topics, $group);
        $output->info('Group [' . $group . '] is ready for ' . implode(', ', $topics) . '.');
        return 0;
    }

    private function publish(Input $input, Output $output, ?string $connection): int
    {
        $topic = (string) $input->argument(0, '');
        $json = (string) $input->argument(1, '{}');
        $payload = json_decode($json, true);
        if ($topic === '' || !is_array($payload)) {
            $output->error('Usage: messaging:publish <topic> \'{"json":"payload"}\'');
            return 1;
        }
        $message = $this->app->make(EventBus::class)->publish($topic, $payload, [], null, $connection);
        $output->info("Published {$message->id} to {$topic}.");
        return 0;
    }
}
