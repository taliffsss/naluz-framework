<?php

declare(strict_types=1);

namespace Naluz\Http;

use Nyholm\Psr7\Response as Psr7Response;

/**
 * PSR-7 response (extends Nyholm's immutable implementation) with convenience factories.
 */
class Response extends Psr7Response
{
    public static function json(mixed $data, int $status = 200, array $headers = []): static
    {
        $body = json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        return new static($status, ['Content-Type' => 'application/json; charset=UTF-8'] + $headers, $body);
    }

    public static function html(string $html, int $status = 200, array $headers = []): static
    {
        return new static($status, ['Content-Type' => 'text/html; charset=UTF-8'] + $headers, $html);
    }

    public static function redirect(string $to, int $status = 302): static
    {
        // Reject header-splitting characters; location is otherwise caller-controlled.
        if (preg_match('/[\r\n]/', $to)) {
            throw new \InvalidArgumentException('Redirect target contains illegal characters.');
        }
        return new static($status, ['Location' => $to]);
    }

    public static function noContent(): static
    {
        return new static(204);
    }
}
