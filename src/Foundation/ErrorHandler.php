<?php

declare(strict_types=1);

namespace Naluz\Foundation;

use Psr\Log\LoggerInterface;

/**
 * Turns PHP-level problems into logged, handled events — not just exceptions that reach the HTTP layer:
 *
 *  - warnings / notices become ErrorException (so they cannot be silently ignored);
 *  - deprecations are logged and execution continues;
 *  - uncaught exceptions (CLI, queue workers, bootstrap) and fatal errors are logged at CRITICAL.
 *
 * If the PSR-3 logger itself fails, messages fall back to PHP's error_log(), so nothing is ever lost silently.
 */
final class ErrorHandler
{
    private const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
    private bool $registered = false;

    /** @param resource|null $stderr where CLI errors are printed (defaults to STDERR) */
    public function __construct(private readonly LoggerInterface $logger, private readonly bool $debug = false, private $stderr = null)
    {
    }

    public function register(): void
    {
        if ($this->registered) {
            return;
        }
        $this->registered = true;
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function(fn () => $this->handleShutdown(error_get_last()));
    }

    public function unregister(): void
    {
        if ($this->registered) {
            restore_error_handler();
            restore_exception_handler();
            $this->registered = false;
        }
    }

    public function handleError(int $level, string $message, string $file = '', int $line = 0): bool
    {
        if (!(error_reporting() & $level)) {
            return false; // respects @ and the configured error_reporting
        }
        if ($level & (E_DEPRECATED | E_USER_DEPRECATED)) {
            $this->log('warning', 'Deprecated: {m} in {f}:{l}', ['m' => $message, 'f' => $file, 'l' => $line]);
            return true;
        }
        throw new \ErrorException($message, 0, $level, $file, $line);
    }

    public function handleException(\Throwable $e): void
    {
        $this->log('critical', 'Uncaught {class}: {m}', ['class' => $e::class, 'm' => $e->getMessage(), 'exception' => $e]);
        if (PHP_SAPI === 'cli') {
            fwrite($this->stderr ?? STDERR, ($this->debug ? $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() : 'An error occurred. See the log for details.') . PHP_EOL);
            return;
        }
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo $this->debug ? htmlspecialchars($e::class . ': ' . $e->getMessage(), ENT_QUOTES) : 'Server Error';
    }

    /** @param array{type:int,message:string,file:string,line:int}|null $error */
    public function handleShutdown(?array $error): void
    {
        if ($error !== null && ($error['type'] & self::FATAL)) {
            $this->log('critical', 'Fatal error: {m} in {f}:{l}', ['m' => $error['message'], 'f' => $error['file'], 'l' => $error['line']]);
        }
    }

    private function log(string $level, string $message, array $context): void
    {
        try {
            $this->logger->log($level, $message, $context);
        } catch (\Throwable) {
            error_log('[naluz] ' . strtr($message, array_map('strval', array_filter($context, 'is_scalar'))));
        }
    }
}
