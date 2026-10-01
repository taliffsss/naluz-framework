<?php

declare(strict_types=1);

namespace Naluz\Cache\Psr6;

use Psr\Cache\InvalidArgumentException as Psr6InvalidArgument;

final class InvalidArgumentException extends \InvalidArgumentException implements Psr6InvalidArgument
{
}
