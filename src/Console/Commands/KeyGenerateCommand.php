<?php

declare(strict_types=1);

namespace Naluz\Console\Commands;

use Naluz\Console\Command;
use Naluz\Console\Input;
use Naluz\Console\Output;
use Naluz\Foundation\Application;
use Naluz\Security\Encrypter;

final class KeyGenerateCommand extends Command
{
    public static function instances(Application $app): array
    {
        return [new self($app)];
    }

    public function name(): string
    {
        return 'key:generate';
    }

    public function description(): string
    {
        return 'Generate APP_KEY (and JWT_SECRET with --jwt) in .env; --show prints instead';
    }

    public function handle(Input $input, Output $output): int
    {
        $values = ['APP_KEY' => Encrypter::generateKey()];
        if ($input->option('jwt')) {
            $values['JWT_SECRET'] = bin2hex(random_bytes(32));
        }
        if ($input->option('show')) {
            foreach ($values as $k => $v) {
                $output->line("{$k}={$v}");
            }
            return 0;
        }
        $path = $this->app->basePath('.env');
        if (!is_file($path) && is_file($this->app->basePath('.env.example'))) {
            copy($this->app->basePath('.env.example'), $path);
        }
        $env = is_file($path) ? (string) file_get_contents($path) : '';
        foreach ($values as $k => $v) {
            $env = preg_match("/^{$k}=.*$/m", $env)
                ? (string) preg_replace("/^{$k}=.*$/m", "{$k}={$v}", $env)
                : rtrim($env) . "\n{$k}={$v}\n";
        }
        file_put_contents($path, $env);
        $output->info('Application key set: ' . implode(', ', array_keys($values)));
        return 0;
    }
}
