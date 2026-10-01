<?php

declare(strict_types=1);

namespace Naluz\GraphQL;

/** Internal: a non-null position produced null, so the nearest nullable parent becomes null. The error is already recorded. */
final class NullBubble extends \RuntimeException
{
}
