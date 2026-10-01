<?php

declare(strict_types=1);

namespace Naluz\Foundation;

use Psr\Http\Message\ResponseInterface;

final class Emitter
{
    public function emit(ResponseInterface $response, bool $headOnly = false): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $i => $value) {
                    header("{$name}: {$value}", $i === 0 && strtolower($name) !== 'set-cookie');
                }
            }
            http_response_code($response->getStatusCode());
        }
        $status = $response->getStatusCode();
        if ($headOnly || $status === 204 || $status === 304) {
            return;
        }
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        while (!$body->eof()) {
            echo $body->read(8192);
        }
    }
}
