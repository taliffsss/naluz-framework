<?php

declare(strict_types=1);

namespace Naluz\Messaging;

/** A received message is oversized, malformed, or fails signature verification. It can never succeed on retry. */
final class InvalidMessageException extends MessagingException
{
}
