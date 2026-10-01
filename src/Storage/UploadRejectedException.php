<?php

declare(strict_types=1);

namespace Naluz\Storage;

use Naluz\Http\HttpException;

/** Rendered as a 422 by the exception handler. */
final class UploadRejectedException extends HttpException
{
    public function __construct(string $reason)
    {
        parent::__construct(422, $reason);
    }
}
