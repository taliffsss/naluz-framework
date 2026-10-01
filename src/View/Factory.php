<?php

declare(strict_types=1);

namespace Naluz\View;

/**
 * Plain-PHP template engine with layouts and sections. Output is NOT auto-escaped:
 * wrap every dynamic value in `e()` / `$this->e()`.
 */
final class Factory
{
    /** @var array<string,mixed> */
    private array $shared = [];

    private readonly Compiler $compiler;

    /**
     * @param string|null $cachePath where compiled templates are stored (defaults to the system temp dir)
     * @param bool $autoReload true: recompile when the template's content changes (development);
     *                         false: trust compiled files forever (production — run `php naluz view:clear` on deploy)
     */
    public function __construct(private readonly string $path, private readonly ?string $cachePath = null, private readonly bool $autoReload = true)
    {
        $this->compiler = new Compiler();
    }

    public function cacheDirectory(): string
    {
        return $this->cachePath ?? sys_get_temp_dir() . '/naluz-views';
    }

    /** Path of the PHP file to include for a template: compiles `*.naluz.php` on demand. */
    public function compiled(string $file): string
    {
        if (!str_ends_with($file, '.naluz.php')) {
            return $file; // plain PHP view
        }
        $dir = $this->cacheDirectory();
        $prefix = hash('sha256', $file);
        // Content-addressed in development, so edits are picked up even within the same second.
        $target = $dir . '/' . $prefix . '-' . ($this->autoReload ? md5_file($file) : 'prod') . '.php';
        if (!is_file($target)) {
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Cannot create view cache directory.');
            }
            $tmp = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
            file_put_contents($tmp, $this->compiler->compile((string) file_get_contents($file)));
            rename($tmp, $target); // atomic: concurrent requests never see a half-written file
            foreach (glob($dir . '/' . $prefix . '-*.php') ?: [] as $old) {
                if ($old !== $target) {
                    @unlink($old); // drop superseded versions of this template
                }
            }
        }
        return $target;
    }

    /** Delete every compiled template. */
    public function clearCache(): int
    {
        $n = 0;
        foreach (glob($this->cacheDirectory() . '/*.php') ?: [] as $f) {
            $n += @unlink($f) ? 1 : 0;
        }
        return $n;
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    public function exists(string $name): bool
    {
        return is_file($this->file($name));
    }

    public function render(string $name, array $data = []): string
    {
        return (new Template($this, $this->file($name)))->render($data + $this->shared);
    }

    public function file(string $name): string
    {
        // Template names come from code, but guard anyway: no traversal, no stream wrappers.
        if (!preg_match('#^[A-Za-z0-9_\-./]+$#', $name) || str_contains($name, '..')) {
            throw new \InvalidArgumentException("Invalid view name [{$name}].");
        }
        $base = rtrim($this->path, '/\\') . '/' . str_replace('.', '/', preg_replace('/\.php$/', '', $name) ?? $name);
        return is_file($base . '.naluz.php') ? $base . '.naluz.php' : $base . '.php';
    }
}
