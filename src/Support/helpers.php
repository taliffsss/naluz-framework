<?php

declare(strict_types=1);

use Naluz\Foundation\Application;
use Naluz\Http\Response;
use Naluz\Support\Collection;
use Naluz\Support\Env;

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}

if (!function_exists('collect')) {
    function collect(iterable $items = []): Collection
    {
        return Collection::make($items);
    }
}

if (!function_exists('app')) {
    /** Resolve the application, or a service from its container. */
    function app(?string $abstract = null): mixed
    {
        $app = Application::getInstance();
        return $abstract === null ? $app : $app->make($abstract);
    }
}

if (!function_exists('config')) {
    function config(?string $key = null, mixed $default = null): mixed
    {
        $config = app(\Naluz\Config\Repository::class);
        return $key === null ? $config : $config->get($key, $default);
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return app()->basePath($path);
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return app()->basePath('storage' . ($path !== '' ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : ''));
    }
}

if (!function_exists('e')) {
    /** HTML-escape a value (use for every piece of untrusted output in views). */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('response')) {
    function response(string $body = '', int $status = 200, array $headers = []): Response
    {
        return new Response($status, $headers, $body);
    }
}

if (!function_exists('json')) {
    function json(mixed $data, int $status = 200, array $headers = []): Response
    {
        return Response::json($data, $status, $headers);
    }
}

if (!function_exists('redirect')) {
    function redirect(string $to, int $status = 302): Response
    {
        return Response::redirect($to, $status);
    }
}

if (!function_exists('view')) {
    function view(string $name, array $data = [], int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'text/html; charset=UTF-8'], app(\Naluz\View\Factory::class)->render($name, $data));
    }
}

if (!function_exists('route')) {
    function route(string $name, array $params = [], bool $absolute = false): string
    {
        return app(\Naluz\Routing\Router::class)->url($name, $params);
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return app(\Naluz\Security\Csrf::class)->token();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('now')) {
    function now(): DateTimeImmutable
    {
        return app(\Psr\Clock\ClockInterface::class)->now();
    }
}

if (!function_exists('method_field')) {
    /** Hidden input for HTML form method spoofing (PUT, PATCH, DELETE only). */
    function method_field(string $method): string
    {
        $method = strtoupper($method);
        if (!in_array($method, ['PUT', 'PATCH', 'DELETE'], true)) {
            throw new InvalidArgumentException("Cannot spoof method [{$method}].");
        }
        return '<input type="hidden" name="_method" value="' . e($method) . '">';
    }
}

if (!function_exists('json_for_html')) {
    /** JSON that is safe inside HTML text, attributes and <script> blocks. */
    function json_for_html(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}

if (!function_exists('logger')) {
    /** Log a message (info level) or get the logger (LogManager: ->channel('slack')) when called without arguments. */
    function logger(?string $message = null, array $context = []): mixed
    {
        $logger = app(\Naluz\Log\LogManager::class);
        if ($message === null) {
            return $logger;
        }
        $logger->info($message, $context);
        return null;
    }
}

if (!function_exists('storage')) {
    function storage(?string $disk = null): \Naluz\Storage\Filesystem
    {
        return app(\Naluz\Storage\StorageManager::class)->disk($disk);
    }
}

if (!function_exists('dispatch')) {
    function dispatch(\Naluz\Queue\Job $job): void
    {
        app(\Naluz\Queue\QueueManager::class)->dispatch($job);
    }
}

if (!function_exists('http')) {
    /** The HTTP client wrapper (Guzzle via PSR-18): `http()->get($url)`. */
    function http(): \Naluz\Http\Client\Http
    {
        return app(\Naluz\Http\Client\Http::class);
    }
}

if (!function_exists('nosql')) {
    /** NoSQL document store: `nosql()->collection('users')->query()->where('age', '>', 18)->get()`. */
    function nosql(?string $connection = null): \Naluz\NoSql\DocumentStore
    {
        return app(\Naluz\NoSql\NoSqlManager::class)->connection($connection);
    }
}
