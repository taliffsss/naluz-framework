<?php

declare(strict_types=1);

namespace Naluz\View;

/**
 * Compiles `*.naluz.php` templates to plain PHP.
 *
 *   {{ $x }}          escaped output (htmlspecialchars)         {!! $x !!}   raw output
 *   {{-- comment --}} removed                                    @{{ $x }}    literal "{{ $x }}"
 *   @if @elseif @else @endif · @unless · @isset · @empty · @foreach · @forelse/@empty/@endforelse · @for · @while
 *   @extends('layout') @section('a') … @endsection · @section('a', 'inline') · @yield('a') · @push/@stack
 *   @include('partial', [...]) · @csrf · @method('PUT') · @json($x) · @auth · @guest · @php … @endphp
 *
 * Output is escaped by default, so forgetting to escape — the classic XSS mistake of plain-PHP views — is impossible
 * without explicitly typing `{!! !!}`.
 */
final class Compiler
{
    private const SIMPLE = [
        'else' => '<?php else: ?>',
        'endif' => '<?php endif; ?>',
        'endunless' => '<?php endif; ?>',
        'endisset' => '<?php endif; ?>',
        'endforeach' => '<?php endforeach; ?>',
        'endfor' => '<?php endfor; ?>',
        'endwhile' => '<?php endwhile; ?>',
        'endswitch' => '<?php endswitch; ?>',
        'endsection' => '<?php $this->endSection(); ?>',
        'endpush' => '<?php $this->endPush(); ?>',
        'endauth' => '<?php endif; ?>',
        'endguest' => '<?php endif; ?>',
        'csrf' => '<?= csrf_field() ?>',
        'break' => '<?php break; ?>',
        'continue' => '<?php continue; ?>',
        'auth' => '<?php if (app(\Naluz\Auth\Auth::class)->check()): ?>',
        'guest' => '<?php if (app(\Naluz\Auth\Auth::class)->guest()): ?>',
    ];

    /** directives taking `( expression )` */
    private const WITH_ARGS = [
        'if' => '<?php if (%s): ?>',
        'elseif' => '<?php elseif (%s): ?>',
        'unless' => '<?php if (!(%s)): ?>',
        'isset' => '<?php if (isset(%s)): ?>',
        'foreach' => '<?php foreach (%s): ?>',
        'for' => '<?php for (%s): ?>',
        'while' => '<?php while (%s): ?>',
        'switch' => '<?php switch (%s): ?>',
        'case' => '<?php case %s: ?>',
        'extends' => '<?php $this->extend(%s); ?>',
        'section' => '<?php $this->section(%s); ?>',
        'push' => '<?php $this->push(%s); ?>',
        'yield' => '<?= $this->yield(%s) ?>',
        'stack' => '<?= $this->stack(%s) ?>',
        'include' => '<?= $this->include(%s) ?>',
        'method' => '<?= method_field(%s) ?>',
        'json' => '<?= json_for_html(%s) ?>',
    ];

    private int $forelse = 0;
    /** @var list<int> */
    private array $forelseStack = [];
    /** @var list<string> */
    private array $protected = [];

    public function compile(string $source): string
    {
        $this->forelse = 0;
        $this->forelseStack = [];
        $this->protected = [];

        // 1. hide things the later passes must not touch
        $source = (string) preg_replace_callback('/@php\b(.*?)@endphp/s', fn ($m) => $this->protect('<?php ' . trim($m[1]) . ' ?>'), $source);
        $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
        $source = (string) preg_replace_callback('/@(\{\{.*?\}\})/s', fn ($m) => $this->protect($m[1]), $source);
        $source = (string) preg_replace_callback('/@@(\w)/', fn ($m) => $this->protect('@' . $m[1]), $source);

        // email addresses ("dev@example.com") must never be mistaken for directives
        $source = (string) preg_replace_callback('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/', fn ($m) => $this->protect($m[0]), $source);

        // 2. echoes
        $source = (string) preg_replace('/\{!!\s*(.+?)\s*!!\}/s', '<?php echo $1; ?>', $source);
        $source = (string) preg_replace('/\{\{\s*(.+?)\s*\}\}/s', '<?= e($1) ?>', $source);

        // 3. directives (balanced parentheses supported: @if(in_array($x, [1, (2)])))
        $source = (string) preg_replace_callback(
            '/(?<!@)@(\w+)(?:[ \t]*(\((?:[^()]++|(?2))*\)))?/',
            fn (array $m) => $this->directive($m),
            $source
        );

        // 4. restore protected fragments (may be nested, hence the loop)
        for ($i = 0; $i < 3; $i++) {
            $source = (string) preg_replace_callback('/\x00P(\d+)\x00/', fn ($m) => $this->protected[(int) $m[1]], $source);
        }
        return "<?php /* compiled by NaluzPHP */ ?>\n" . $source;
    }

    private function protect(string $code): string
    {
        $this->protected[] = $code;
        return "\x00P" . (count($this->protected) - 1) . "\x00";
    }

    /** @param array<int,string> $m */
    private function directive(array $m): string
    {
        $name = $m[1];
        $args = isset($m[2]) ? substr($m[2], 1, -1) : null; // strip the outer parentheses

        if ($name === 'forelse' && $args !== null) {
            $id = ++$this->forelse;
            $this->forelseStack[] = $id;
            return "<?php \$__empty{$id} = true; foreach ({$args}): \$__empty{$id} = false; ?>";
        }
        if ($name === 'empty') {
            if ($args === null) { // closing branch of @forelse
                $id = end($this->forelseStack) ?: throw new \LogicException('@empty without @forelse.');
                return "<?php endforeach; if (\$__empty{$id}): ?>";
            }
            return "<?php if (empty({$args})): ?>";
        }
        if ($name === 'endempty') {
            return '<?php endif; ?>';
        }
        if ($name === 'endforelse') {
            array_pop($this->forelseStack);
            return '<?php endif; ?>';
        }
        if ($name === 'default') {
            return '<?php default: ?>';
        }
        if (isset(self::WITH_ARGS[$name]) && $args !== null) {
            return sprintf(self::WITH_ARGS[$name], $args);
        }
        if (isset(self::SIMPLE[$name]) && $args === null) {
            return self::SIMPLE[$name];
        }
        return $m[0]; // not ours (e.g. "@media" in CSS, "@example"): leave untouched
    }
}
