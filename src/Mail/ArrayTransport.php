<?php

declare(strict_types=1);

namespace Naluz\Mail;

/** Keeps messages in memory; the transport to assert against in tests. */
final class ArrayTransport implements Transport
{
    /** @var list<Message> */
    public array $sent = [];

    public function send(Message $message): void
    {
        $message->toMime(); // validate exactly as a real transport would
        $this->sent[] = $message;
    }
}
