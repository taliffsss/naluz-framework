<?php

declare(strict_types=1);

namespace Naluz\Database\Orm;

use Naluz\Http\HttpException;

/** Extends HttpException so an uncaught findOrFail() becomes a 404 response automatically. */
final class ModelNotFoundException extends HttpException
{
    public function __construct(public readonly string $model, public readonly array|int|string $ids = [])
    {
        parent::__construct(404, 'No query results for model [' . $model . ']' . ($ids !== [] ? ' ' . implode(', ', (array) $ids) : '') . '.');
    }
}
