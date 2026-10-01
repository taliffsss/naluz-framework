<?php

declare(strict_types=1);

namespace Naluz\Storage;

use Naluz\Config\Repository;

final class StorageManager
{
    /** @var array<string,Filesystem> */
    private array $disks = [];

    public function __construct(private readonly Repository $config, private readonly string $basePath)
    {
    }

    public function disk(?string $name = null): Filesystem
    {
        $name ??= (string) $this->config->get('filesystems.default', 'local');
        return $this->disks[$name] ??= $this->make($name);
    }

    public function extend(string $name, Filesystem $disk): void
    {
        $this->disks[$name] = $disk;
    }

    private function make(string $name): Filesystem
    {
        $cfg = $this->config->get("filesystems.disks.{$name}") ?? throw new \InvalidArgumentException("Disk [{$name}] is not configured.");
        return match ($cfg['driver'] ?? 'local') {
            'local' => new LocalFilesystem(
                str_starts_with((string) $cfg['root'], '/') ? (string) $cfg['root'] : $this->basePath . '/' . $cfg['root'],
                (string) ($cfg['url'] ?? '')
            ),
            default => throw new \InvalidArgumentException("Unsupported disk driver [{$cfg['driver']}]. Register your own with StorageManager::extend()."),
        };
    }
}
