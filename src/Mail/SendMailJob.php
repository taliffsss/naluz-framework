<?php

declare(strict_types=1);

namespace Naluz\Mail;

use Naluz\Queue\Job;

final class SendMailJob extends Job
{
    protected int $tries = 5;
    protected int|array $backoff = [10, 60, 300, 900];

    /** @param array<string,mixed> $message */
    public function __construct(public readonly array $message = [])
    {
    }

    public function handle(Mailer $mailer): void
    {
        $mailer->send(Message::fromArray($this->message));
    }
}
