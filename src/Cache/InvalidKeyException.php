<?php

declare(strict_types=1);

namespace Naluz\Cache;

use Psr\SimpleCache\InvalidArgumentException;

final class InvalidKeyException extends \InvalidArgumentException implements InvalidArgumentException
{
}
