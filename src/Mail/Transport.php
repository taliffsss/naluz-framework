<?php

declare(strict_types=1);

namespace Naluz\Mail;

interface Transport
{
    /** @throws MailException */
    public function send(Message $message): void;
}
