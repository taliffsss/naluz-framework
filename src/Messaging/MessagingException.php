<?php

declare(strict_types=1);

namespace Naluz\Messaging;

/** A broker could not be reached or is misconfigured, or a message could not be published. */
class MessagingException extends \RuntimeException
{
}
