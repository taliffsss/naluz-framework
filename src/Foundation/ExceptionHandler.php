<?php

declare(strict_types=1);

namespace Naluz\Foundation;

use Naluz\Config\Repository;
use Naluz\Http\HttpException;
use Naluz\Http\Request;
use Naluz\Http\Response;
use Naluz\Session\Store;
use Naluz\Validation\ValidationException;
use Naluz\View\Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/** Converts any Throwable into a response. Internals are only exposed when `app.debug` is true. */
class ExceptionHandler
{
    private const REASONS = [
        400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
        405 => 'Method Not Allowed', 409 => 'Conflict', 419 => 'Page Expired', 422 => 'Unprocessable Content',
        429 => 'Too Many Requests', 500 => 'Server Error', 503 => 'Service Unavailable',
    ];

    public function __construct(
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
        private readonly Factory $views,
        private readonly Store $session,
    ) {
    }

    public function render(\Throwable $e, ServerRequestInterface $request): ResponseInterface
    {
        $json = Request::expectsJson($request);

        if ($e instanceof ValidationException) {
            return $json ? Response::json(['message' => $e->getMessage(), 'errors' => $e->errors], 422) : $this->redirectBack($e, $request);
        }

        $status = $e instanceof HttpException ? $e->status() : 500;
        $headers = $e instanceof HttpException ? $e->headers() : [];
        if ($status >= 500) {
            $this->logger->error('{class}: {message}', ['class' => $e::class, 'message' => $e->getMessage(), 'exception' => $e]);
        }

        $debug = (bool) $this->config->get('app.debug', false);
        $message = ($e instanceof HttpException && $e->getMessage() !== '') || $debug
            ? $e->getMessage()
            : (self::REASONS[$status] ?? 'Error');
        if ($status >= 500 && !$debug) {
            $message = 'Server Error';
        }

        if ($json) {
            $body = ['message' => $message];
            if ($debug && $status >= 500) {
                $body['exception'] = $e::class;
                $body['file'] = $e->getFile() . ':' . $e->getLine();
                $body['trace'] = array_slice(array_map(fn ($f) => ($f['file'] ?? '') . ':' . ($f['line'] ?? ''), $e->getTrace()), 0, 15);
            }
            return Response::json($body, $status, $headers);
        }
        return Response::html($this->html($status, $message, $debug && $status >= 500 ? $e : null), $status, $headers);
    }

    private function html(int $status, string $message, ?\Throwable $e): string
    {
        if ($e === null && $this->views->exists("errors/{$status}")) {
            return $this->views->render("errors/{$status}", ['message' => $message, 'status' => $status]);
        }
        $title = e($status . ' ' . (self::REASONS[$status] ?? 'Error'));
        $trace = $e ? '<pre>' . e($e::class . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString()) . '</pre>' : '';
        return "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\"><title>{$title}</title>"
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<style>body{font-family:system-ui,sans-serif;margin:10vh auto;max-width:42rem;padding:0 1rem;color:#222}pre{overflow:auto;background:#f5f5f5;padding:1rem}</style></head>'
            . '<body><h1>' . $title . '</h1><p>' . e($message) . '</p>' . $trace . '</body></html>';
    }

    private function redirectBack(ValidationException $e, ServerRequestInterface $request): ResponseInterface
    {
        $this->session->flash('errors', $e->errors);
        $old = array_filter(
            $e->input,
            static fn ($k) => !preg_match('/password|_token|secret/i', (string) $k),
            ARRAY_FILTER_USE_KEY
        );
        $this->session->flash('old', $old);

        // Only ever redirect to a path on this host (no open redirect through a forged Referer).
        $back = '/';
        $referer = $request->getHeaderLine('Referer');
        if ($referer !== '' && parse_url($referer, PHP_URL_HOST) === $request->getUri()->getHost()) {
            $path = (string) parse_url($referer, PHP_URL_PATH);
            $query = parse_url($referer, PHP_URL_QUERY);
            $back = ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//') ? '/' : $path) . ($query ? '?' . $query : '');
        }
        return Response::redirect($back, 303);
    }
}
