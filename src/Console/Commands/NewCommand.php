<?php

declare(strict_types=1);

namespace Naluz\Console\Commands;

use Naluz\Console\Command;
use Naluz\Console\Input;
use Naluz\Console\Output;
use Naluz\Foundation\Application;
use Naluz\Security\Encrypter;

/**
 * `php naluz new my-app` — scaffold a fresh project from this one (framework + starter app), generate secrets,
 * and install dependencies.  Options: --name=vendor/package  --no-install  --dir=/parent/dir
 */
final class NewCommand extends Command
{
    /** top-level paths never copied into a new project */
    private const SKIP = ['.git', 'vendor', '.env', '.phpunit.cache', '.phpunit.result.cache', 'node_modules', '.idea', '.vscode'];

    public static function instances(Application $app): array
    {
        return [new self($app)];
    }

    public function name(): string
    {
        return 'new';
    }

    public function description(): string
    {
        return 'Create a new project: new <name> [--name=vendor/pkg] [--no-install] [--dir=path]';
    }

    public function handle(Input $input, Output $output): int
    {
        $name = $input->argument(0);
        if ($name === null || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $name)) {
            $output->error('Usage: php naluz new <directory-name>   (letters, digits, . _ -)');
            return 1;
        }
        $package = $input->option('name');
        if (is_string($package) && !preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*$#', $package)) {
            $output->error('--name must look like vendor/package (lowercase).');
            return 1;
        }

        $parent = is_string($input->option('dir')) ? rtrim((string) $input->option('dir'), '/') : (string) getcwd();
        $target = $parent . '/' . $name;
        if (file_exists($target) && (!is_dir($target) || (scandir($target) ?: []) !== ['.', '..'])) {
            $output->error("Directory [{$target}] already exists and is not empty.");
            return 1;
        }

        $source = rtrim($this->app->basePath(), '/\\');
        $realParent = realpath($parent) ?: $parent;
        if ($realParent === $source || str_starts_with($realParent . '/', $source . '/')) {
            $output->error('Choose a target directory outside the framework project (it would be copied into itself).');
            return 1;
        }
        $output->line("Creating project in {$target} ...");
        $this->copy($source, $target, $source);

        // secrets & env
        copy($target . '/.env.example', $target . '/.env');
        $env = (string) file_get_contents($target . '/.env');
        $env = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . Encrypter::generateKey(), $env);
        $env = preg_replace('/^JWT_SECRET=.*$/m', 'JWT_SECRET=' . bin2hex(random_bytes(32)), (string) $env);
        $env = preg_replace('/^APP_NAME=.*$/m', 'APP_NAME=' . preg_replace('/[^A-Za-z0-9 ._-]/', '', $name), (string) $env);
        file_put_contents($target . '/.env', $env);
        @touch($target . '/storage/database.sqlite');

        if (is_string($package)) {
            $composer = json_decode((string) file_get_contents($target . '/composer.json'), true);
            $composer['name'] = $package;
            file_put_contents($target . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        }
        @chmod($target . '/naluz', 0755);

        if (!$input->option('no-install')) {
            $output->line('Installing dependencies (composer install) ...');
            $code = $this->composerInstall($target);
            if ($code !== 0) {
                $output->warn("composer install failed (exit {$code}); run it yourself inside {$target}.");
            }
        }

        $output->info("Project created. Next steps:\n  cd {$name}\n  " . ($input->option('no-install') ? "composer install\n  " : '') . "php naluz migrate\n  php naluz run:server");
        return 0;
    }

    private function copy(string $from, string $to, string $root): void
    {
        if (!is_dir($to) && !mkdir($to, 0775, true) && !is_dir($to)) {
            throw new \RuntimeException("Cannot create [{$to}].");
        }
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $src = $from . '/' . $entry;
            $rel = ltrim(substr($src, strlen($root)), '/');
            if (is_link($src) || ($from === $root && in_array($entry, self::SKIP, true))) {
                continue; // symlinks are never followed out of the source tree
            }
            // runtime data: keep the directory and its .gitignore, drop the contents
            if (preg_match('#^(storage|public/storage)/#', $rel) && !is_dir($src) && $entry !== '.gitignore') {
                continue;
            }
            if (preg_match('#^storage/[^/]+\.sqlite$#', $rel)) {
                continue;
            }
            is_dir($src) ? $this->copy($src, $to . '/' . $entry, $root) : copy($src, $to . '/' . $entry);
        }
    }

    private function composerInstall(string $dir): int
    {
        $bin = trim((string) shell_exec('command -v composer 2>/dev/null'));
        if ($bin === '') {
            return 127;
        }
        $proc = proc_open([$bin, 'install', '--no-interaction', '--prefer-dist'], [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $dir);
        return is_resource($proc) ? proc_close($proc) : 1;
    }
}
