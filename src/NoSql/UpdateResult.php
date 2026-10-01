<?php

declare(strict_types=1);

namespace Naluz\NoSql;

final class UpdateResult
{
    public function __construct(
        public readonly int $matched,
        public readonly int $modified,
        public readonly string|int|null $upsertedId = null,
    ) {
    }
}
