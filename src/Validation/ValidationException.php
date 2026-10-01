<?php

declare(strict_types=1);

namespace Naluz\Validation;

use Naluz\Http\HttpException;

final class ValidationException extends HttpException
{
    /** @param array<string,list<string>> $errors */
    public function __construct(public readonly array $errors, public readonly array $input = [])
    {
        parent::__construct(422, 'The given data was invalid.');
    }
}
