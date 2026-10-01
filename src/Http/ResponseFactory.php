<?php

declare(strict_types=1);

namespace Naluz\Http;

use Psr\Http\Message\ResponseInterface;

/** Turns whatever a controller returned into a PSR-7 response. */
final class ResponseFactory
{
    public static function from(mixed $result): ResponseInterface
    {
        return match (true) {
            $result instanceof ResponseInterface => $result,
            $result === null => Response::noContent(),
            is_array($result), $result instanceof \JsonSerializable => Response::json($result),
            is_string($result), $result instanceof \Stringable, is_scalar($result) => Response::html((string) $result),
            default => throw new \LogicException('Controller returned an unsupported value of type ' . get_debug_type($result) . '.'),
        };
    }
}
