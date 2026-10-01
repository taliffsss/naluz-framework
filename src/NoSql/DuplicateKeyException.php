<?php

declare(strict_types=1);

namespace Naluz\NoSql;

/** A unique index (or `_id`) would be violated. */
final class DuplicateKeyException extends \RuntimeException
{
}
