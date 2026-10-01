<?php

declare(strict_types=1);

namespace Naluz\Console\Commands;

use Naluz\Console\Command;
use Naluz\Console\Input;
use Naluz\Console\Output;
use Naluz\Foundation\Application;
use Naluz\Support\Env;

/**
 * `php naluz run:server` — start the PHP development server for public/.
 *
 *   php naluz run:server                       http://127.0.0.1:8000
 *   php naluz run:server --port=8001
 *   php naluz run:server --host=0.0.0.0 --port=8080 --workers=4
 *
 * Defaults come from APP_HOST / APP_PORT in .env. For development only: use nginx/Apache + php-fpm in production.
 */
final class RunServerCommand extends Command
{
    public static function instances(Application $app): array
    {
        return [new self($app)];
    }

    public function name(): string
    {
        return 'run:server';
    }

    public function description(): string
    {
        return 'Start the development server (--port=8001 --host=127.0.0.1 --workers=N)';
    }

    public function handle(Input $input, Output $output): int
    {
        $host = $this->option($input, 'host', (string) (Env::get('APP_HOST') ?: '127.0.0.1'));
        $port = $this->option($input, 'port', (string) (Env::get('APP_PORT') ?: '8000'));
        $workers = $input->option('workers');

        if (!preg_match('/^[A-Za-z0-9.\-]+$|^\[[0-9A-Fa-f:]+\]$/', $host)) {
            $output->error("Invalid --host [{$host}].");
            return 1;
        }
        if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            $output->error("Invalid --port [{$port}]: use a number between 1 and 65535, e.g. --port=8001.");
            return 1;
        }
        if ($workers !== null && $workers !== false && (!is_string($workers) || !ctype_digit($workers) || (int) $workers < 1 || (int) $workers > 64)) {
            $output->error('Invalid --workers: use a number between 1 and 64.');
            return 1;
        }

        $docroot = $this->app->basePath('public');
        if (!is_dir($docroot)) {
            $output->error("Document root [{$docroot}] does not exist.");
            return 1;
        }
        if (!$input->option('dry-run') && ($busy = $this->portInUse($host, (int) $port)) !== null) {
            $output->error("Port {$port} on {$host} is already in use ({$busy}). Try another: php naluz run:server --port=" . ((int) $port + 1));
            return 1;
        }

        $command = [PHP_BINARY, '-S', "{$host}:{$port}", '-t', $docroot];
        $env = is_string($workers) ? ['PHP_CLI_SERVER_WORKERS' => $workers] : [];
        if ($input->option('dry-run')) {
            $output->line(implode(' ', array_map('escapeshellarg', $command)) . ($env ? '   (PHP_CLI_SERVER_WORKERS=' . $workers . ')' : ''));
            return 0;
        }

        $output->info("NaluzPHP development server started: http://{$host}:{$port}  (Ctrl+C to stop)");
        $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, null, $env === [] ? null : $env + getenv());
        return is_resource($process) ? proc_close($process) : 1;
    }

    /** `--port=8001` or `--port 8001` */
    private function option(Input $input, string $name, string $default): string
    {
        $value = $input->option($name);
        if ($value === true) { // "--port 8001": the value landed in the positional arguments
            $value = $input->argument(0);
        }
        return is_string($value) && $value !== '' ? $value : $default;
    }

    /** @return string|null why the port can't be used, or null when it is free */
    private function portInUse(string $host, int $port): ?string
    {
        $socket = @stream_socket_server('tcp://' . trim($host, '[]') . ':' . $port, $errno, $error);
        if ($socket === false) {
            return $error !== '' ? $error : "error {$errno}";
        }
        fclose($socket);
        return null;
    }
}
