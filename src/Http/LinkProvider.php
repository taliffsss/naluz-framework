<?php

declare(strict_types=1);

namespace Naluz\Http;

use Psr\Link\EvolvableLinkProviderInterface;
use Psr\Link\LinkInterface;
use Psr\Http\Message\ResponseInterface;

/** PSR-13 link provider, plus RFC 8288 `Link:` header serialisation. */
final class LinkProvider implements EvolvableLinkProviderInterface
{
    /** @param list<LinkInterface> $links */
    public function __construct(private array $links = [])
    {
    }

    public function getLinks(): iterable
    {
        return $this->links;
    }

    public function getLinksByRel(string $rel): iterable
    {
        return array_values(array_filter($this->links, fn (LinkInterface $l) => in_array($rel, $l->getRels(), true)));
    }

    public function withLink(LinkInterface $link): static
    {
        $c = clone $this;
        $c->links[] = $link;
        return $c;
    }

    public function withoutLink(LinkInterface $link): static
    {
        $c = clone $this;
        $c->links = array_values(array_filter($this->links, fn ($l) => $l !== $link));
        return $c;
    }

    /** `<https://x/page2>; rel="next"; title="Next"` — values with CR/LF are rejected (header injection). */
    public static function header(iterable $links): string
    {
        $out = [];
        foreach ($links as $link) {
            $part = '<' . self::safe($link->getHref()) . '>';
            if ($link->getRels() !== []) {
                $part .= '; rel="' . self::safe(implode(' ', $link->getRels())) . '"';
            }
            foreach ($link->getAttributes() as $name => $value) {
                if (!preg_match('/^[A-Za-z][A-Za-z0-9*-]*$/', (string) $name)) {
                    throw new \InvalidArgumentException('Invalid link attribute name.');
                }
                foreach ((array) $value as $v) {
                    $part .= is_bool($v) ? ($v ? "; {$name}" : '') : "; {$name}=\"" . addcslashes(self::safe((string) $v), '"\\') . '"';
                }
            }
            $out[] = $part;
        }
        return implode(', ', $out);
    }

    public function applyTo(ResponseInterface $response): ResponseInterface
    {
        $header = self::header($this->links);
        return $header === '' ? $response : $response->withAddedHeader('Link', $header);
    }

    private static function safe(string $value): string
    {
        if (preg_match('/[\r\n\x00>]/', $value)) {
            throw new \InvalidArgumentException('Illegal character in link value.');
        }
        return $value;
    }
}
