<?php

declare(strict_types=1);

namespace Naluz\Database;

use Naluz\Support\Collection;

final class Paginator implements \JsonSerializable, \IteratorAggregate, \Countable
{
    public function __construct(
        public readonly Collection $items,
        public readonly int $total,
        public readonly int $perPage,
        public readonly int $currentPage,
    ) {
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }

    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage();
    }

    public function getIterator(): \Traversable
    {
        return $this->items->getIterator();
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * RFC 8288 pagination links for the `Link` response header: first / prev / next / last.
     * `$url` is a base URL; `page` (and any extra query) is appended.
     */
    public function links(string $url, array $query = []): \Naluz\Http\LinkProvider
    {
        $make = fn (int $page, string $rel) => new \Naluz\Http\Link(
            $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query + ['page' => $page, 'per_page' => $this->perPage]),
            [$rel]
        );
        $links = [$make(1, 'first')];
        if ($this->currentPage > 1) {
            $links[] = $make(min($this->currentPage - 1, $this->lastPage()), 'prev');
        }
        if ($this->hasMorePages()) {
            $links[] = $make($this->currentPage + 1, 'next');
        }
        $links[] = $make($this->lastPage(), 'last');
        return new \Naluz\Http\LinkProvider($links);
    }

    public function jsonSerialize(): array
    {
        return [
            'data' => $this->items->toArray(),
            'meta' => [
                'total' => $this->total,
                'per_page' => $this->perPage,
                'current_page' => $this->currentPage,
                'last_page' => $this->lastPage(),
            ],
        ];
    }
}
