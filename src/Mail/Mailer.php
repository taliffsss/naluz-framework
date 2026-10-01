<?php

declare(strict_types=1);

namespace Naluz\Mail;

use Naluz\Config\Repository;
use Naluz\Queue\QueueManager;
use Naluz\View\Factory;

final class Mailer
{
    public function __construct(
        private readonly Transport $transport,
        private readonly Repository $config,
        private readonly Factory $views,
        private readonly ?QueueManager $queues = null,
    ) {
    }

    /** A message pre-filled with the configured From address. */
    public function message(): Message
    {
        $m = new Message();
        $address = $this->config->get('mail.from.address');
        if ($address) {
            $m->from((string) $address, $this->config->get('mail.from.name'));
        }
        return $m;
    }

    /** Render an (auto-escaping) template as the HTML body; the plain-text part is derived from it. */
    public function view(Message $message, string $view, array $data = []): Message
    {
        return $message->html($this->views->render($view, $data));
    }

    public function send(Message $message): void
    {
        $this->transport->send($message);
    }

    /** Send in the background via the queue. */
    public function queue(Message $message, ?string $queue = null): void
    {
        if ($this->queues === null) {
            throw new MailException('Queue is not available.');
        }
        $job = new SendMailJob($message->toArray());
        $this->queues->dispatch($queue !== null ? $job->onQueue($queue) : $job);
    }
}
