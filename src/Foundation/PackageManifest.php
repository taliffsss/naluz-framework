<?php

declare(strict_types=1);

namespace Naluz\Foundation;

/**
 * Package auto-discovery: a Composer package announces its service providers in its own composer.json,
 *
 *     "extra": { "naluz": { "providers": ["Acme\\Blog\\BlogServiceProvider"] } }
 *
 * and they are registered automatically after `composer require`. Opt a package out with
 * `"extra": {"naluz": {"dont-discover": ["acme/blog"]}}` in your application's composer.json (or `app.dont_discover`).
 */
final class PackageManifest
{
    public function __construct(private readonly string $basePath, private readonly string $vendorPath = '')
    {
    }

    /** @return list<class-string<ServiceProvider>> */
    public function providers(array $skip = []): array
    {
        $vendor = $this->vendorPath !== '' ? $this->vendorPath : $this->basePath . '/vendor';
        $installed = $vendor . '/composer/installed.json';
        if (!is_file($installed)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($installed), true);
        $packages = is_array($data) ? ($data['packages'] ?? $data) : [];

        $skip = [...$skip, ...$this->rootSkips()];
        $providers = [];
        foreach ($packages as $package) {
            if (!is_array($package) || in_array($package['name'] ?? '', $skip, true) || in_array('*', $skip, true)) {
                continue;
            }
            foreach ((array) ($package['extra']['naluz']['providers'] ?? []) as $class) {
                // Only real ServiceProviders: a malicious/buggy package can't make us instantiate arbitrary classes.
                if (is_string($class) && class_exists($class) && is_subclass_of($class, ServiceProvider::class)) {
                    $providers[] = $class;
                }
            }
        }
        return array_values(array_unique($providers));
    }

    /** @return list<string> */
    private function rootSkips(): array
    {
        $file = $this->basePath . '/composer.json';
        $root = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        return (array) ($root['extra']['naluz']['dont-discover'] ?? []);
    }
}
