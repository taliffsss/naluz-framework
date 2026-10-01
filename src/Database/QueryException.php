<?php

declare(strict_types=1);

namespace Naluz\Database;

/** Wraps PDOException. The message contains the SQL but never the bound values (they may be secrets). */
final class QueryException extends \RuntimeException
{
    public function __construct(public readonly string $sql, public readonly array $bindings, \PDOException $previous)
    {
        parent::__construct(sprintf('%s (SQL: %s)', $previous->getMessage(), $sql), (int) $previous->getCode(), $previous);
    }
}
