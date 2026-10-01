<?php

declare(strict_types=1);

namespace Naluz\Http;

use Psr\Link\EvolvableLinkInterface;

/** PSR-13 hypermedia link (RFC 8288 web link). */
final class Link implements EvolvableLinkInterface
{
    /** @var list<string> */
    private array $rels;
    /** @var array<string,string|\Stringable|int|float|bool|list<string|\Stringable|int|float|bool>> */
    private array $attributes;

    /** @param list<string> $rels */
    public function __construct(private string $href, array $rels = [], array $attributes = [])
    {
        $this->rels = array_values(array_unique($rels));
        $this->attributes = $attributes;
    }

    public function getHref(): string
    {
        return $this->href;
    }

    public function isTemplated(): bool
    {
        return (bool) preg_match('/\{[^}]+\}/', $this->href);
    }

    public function getRels(): array
    {
        return $this->rels;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function withHref(string|\Stringable $href): static
    {
        $c = clone $this;
        $c->href = (string) $href;
        return $c;
    }

    public function withRel(string $rel): static
    {
        $c = clone $this;
        $c->rels = array_values(array_unique([...$this->rels, $rel]));
        return $c;
    }

    public function withoutRel(string $rel): static
    {
        $c = clone $this;
        $c->rels = array_values(array_diff($this->rels, [$rel]));
        return $c;
    }

    public function withAttribute(string $attribute, string|\Stringable|int|float|bool|array $value): static
    {
        $c = clone $this;
        $c->attributes[$attribute] = $value;
        return $c;
    }

    public function withoutAttribute(string $attribute): static
    {
        $c = clone $this;
        unset($c->attributes[$attribute]);
        return $c;
    }
}
