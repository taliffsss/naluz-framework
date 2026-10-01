<?php

declare(strict_types=1);

namespace Naluz\Mail;

use Psr\Log\LoggerInterface;

/** Development transport: writes the message to the log instead of sending it. */
final class LogTransport implements Transport
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function send(Message $message): void
    {
        $this->logger->info("Mail (not sent, log transport):\n{mime}", ['mime' => str_replace("\r\n", ' | ', $message->toMime())]);
    }
}
