<?php

declare(strict_types=1);

namespace Naluz\View;

/** The `$this` inside a template file. */
final class Template
{
    private ?string $layout = null;
    private array $layoutData = [];
    /** @var array<string,string> */
    private array $sections = [];
    /** @var list<string> */
    private array $stack = [];
    /** @var array<string,list<string>> */
    private array $pushes = [];
    private array $data = [];

    public function __construct(private readonly Factory $factory, private readonly string $file)
    {
    }

    public function render(array $data): string
    {
        if (!is_file($this->file)) {
            throw new \InvalidArgumentException('View not found: ' . basename($this->file));
        }
        $this->data = $data;
        $content = $this->capture($this->factory->compiled($this->file), $data);
        if ($this->layout !== null) {
            $layout = new self($this->factory, $this->factory->file($this->layout));
            $layout->sections = $this->sections + ['content' => $content];
            $layout->pushes = $this->pushes;
            return $layout->render($this->layoutData + $data);
        }
        return $content;
    }

    private function capture(string $__file, array $__data): string
    {
        extract($__data, EXTR_SKIP);
        ob_start();
        try {
            include $__file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    public function e(mixed $value): string
    {
        return e($value);
    }

    public function extend(string $layout, array $data = []): void
    {
        $this->layout = $layout;
        $this->layoutData = $data;
    }

    /** `section('a')` opens a buffered section; `section('a', 'text')` sets an (escaped) inline value. */
    public function section(string $name, ?string $content = null): void
    {
        if ($content !== null) {
            $this->sections[$name] = e($content);
            return;
        }
        $this->stack[] = $name;
        ob_start();
    }

    public function push(string $stack): void
    {
        $this->stack[] = "\0push:" . $stack;
        ob_start();
    }

    public function endPush(): void
    {
        $name = array_pop($this->stack) ?? throw new \LogicException('endPush() without push().');
        $this->pushes[substr($name, 6)][] = (string) ob_get_clean();
    }

    public function stack(string $name): string
    {
        return implode('', $this->pushes[$name] ?? []);
    }

    public function endSection(): void
    {
        $name = array_pop($this->stack) ?? throw new \LogicException('endSection() without section().');
        $this->sections[$name] = (string) ob_get_clean();
    }

    /** Output a section (unescaped: sections are rendered template output). */
    public function yield(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    public function include(string $name, array $data = []): string
    {
        return $this->factory->render($name, $data + $this->data);
    }
}
