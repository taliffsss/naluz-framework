<?php

declare(strict_types=1);

namespace Naluz\Validation;

use Naluz\Database\Connection;
use Naluz\Support\Arr;

/**
 * Rule-string validator: `['email' => 'required|email|max:255|unique:users,email']`.
 * Supports dotted paths and `*` wildcards (`items.*.qty`). Rules may also be arrays containing closures
 * `fn ($value, $field, $data): ?string` that return an error message or null.
 */
final class Validator
{
    private const MESSAGES = [
        'required' => 'The :field field is required.',
        'string' => 'The :field must be a string.',
        'integer' => 'The :field must be an integer.',
        'numeric' => 'The :field must be a number.',
        'boolean' => 'The :field must be true or false.',
        'array' => 'The :field must be an array.',
        'email' => 'The :field must be a valid email address.',
        'url' => 'The :field must be a valid URL.',
        'date' => 'The :field must be a valid date.',
        'alpha' => 'The :field may only contain letters.',
        'alpha_num' => 'The :field may only contain letters and numbers.',
        'alpha_dash' => 'The :field may only contain letters, numbers, dashes and underscores.',
        'uuid' => 'The :field must be a valid UUID.',
        'min' => 'The :field must be at least :0.',
        'max' => 'The :field may not be greater than :0.',
        'between' => 'The :field must be between :0 and :1.',
        'in' => 'The selected :field is invalid.',
        'not_in' => 'The selected :field is invalid.',
        'regex' => 'The :field format is invalid.',
        'confirmed' => 'The :field confirmation does not match.',
        'same' => 'The :field and :0 must match.',
        'different' => 'The :field and :0 must be different.',
        'unique' => 'The :field has already been taken.',
        'exists' => 'The selected :field is invalid.',
    ];

    /** @var array<string,list<string>> */
    private array $errors = [];
    private bool $ran = false;

    /** @param array<string,string|list<string|\Closure>> $rules @param array<string,string> $messages */
    public function __construct(
        private readonly array $data,
        private readonly array $rules,
        private readonly array $messages = [],
        private readonly ?Connection $db = null,
    ) {
    }

    public static function make(array $data, array $rules, array $messages = [], ?Connection $db = null): self
    {
        return new self($data, $rules, $messages, $db);
    }

    public function fails(): bool
    {
        return $this->errors() !== [];
    }

    public function passes(): bool
    {
        return !$this->fails();
    }

    /** @return array<string,list<string>> */
    public function errors(): array
    {
        if (!$this->ran) {
            $this->ran = true;
            foreach ($this->rules as $field => $rules) {
                foreach ($this->expand($field) as $concrete) {
                    $this->validateField($concrete, is_string($rules) ? explode('|', $rules) : $rules);
                }
            }
        }
        return $this->errors;
    }

    /** Only the fields that have rules, once validation passed. @throws ValidationException */
    public function validate(): array
    {
        if ($this->fails()) {
            throw new ValidationException($this->errors, $this->data);
        }
        return $this->validated();
    }

    public function validated(): array
    {
        $out = [];
        foreach (array_keys($this->rules) as $pattern) {
            foreach ($this->expand($pattern) as $field) {
                if (Arr::has($this->data, $field)) {
                    Arr::set($out, $field, Arr::get($this->data, $field));
                }
            }
        }
        return $out;
    }

    /** @return list<string> */
    private function expand(string $pattern): array
    {
        if (!str_contains($pattern, '*')) {
            return [$pattern];
        }
        $paths = [''];
        foreach (explode('.', $pattern) as $segment) {
            $next = [];
            foreach ($paths as $base) {
                if ($segment === '*') {
                    $node = $base === '' ? $this->data : Arr::get($this->data, $base);
                    foreach (is_array($node) ? array_keys($node) : [] as $key) {
                        $next[] = ltrim($base . '.' . $key, '.');
                    }
                } else {
                    $next[] = ltrim($base . '.' . $segment, '.');
                }
            }
            $paths = $next;
        }
        return $paths;
    }

    /** @param list<string|\Closure> $rules */
    private function validateField(string $field, array $rules): void
    {
        $value = Arr::get($this->data, $field);
        $nullable = in_array('nullable', $rules, true);
        $present = Arr::has($this->data, $field) && $value !== null && $value !== '' && $value !== [];

        foreach ($rules as $rule) {
            if ($rule instanceof \Closure) {
                if ($present && ($msg = $rule($value, $field, $this->data)) !== null) {
                    $this->errors[$field][] = $msg;
                }
                continue;
            }
            [$name, $args] = array_pad(explode(':', $rule, 2), 2, null);
            $params = $args === null ? [] : ($name === 'regex' ? [$args] : explode(',', $args));

            if ($name === 'nullable' || $name === 'sometimes') {
                continue;
            }
            if ($name === 'required') {
                if (!$present && $value !== false && $value !== 0 && $value !== '0') {
                    $this->fail($field, $name, $params);
                    return;
                }
                continue;
            }
            if (!$present) {
                if ($nullable || !in_array('required', $rules, true)) {
                    return; // optional & empty: skip remaining rules
                }
                continue;
            }
            if (!$this->check($name, $value, $params, $field, in_array('integer', $rules, true) || in_array('numeric', $rules, true))) {
                $this->fail($field, $name, $params);
            }
        }
    }

    private function check(string $rule, mixed $v, array $p, string $field, bool $numeric): bool
    {
        return match ($rule) {
            'string' => is_string($v),
            'integer' => is_int($v) || (is_string($v) && preg_match('/^-?\d+$/', $v) === 1),
            'numeric' => is_numeric($v),
            'boolean' => in_array($v, [true, false, 0, 1, '0', '1', 'true', 'false'], true),
            'array' => is_array($v),
            'email' => is_string($v) && filter_var($v, FILTER_VALIDATE_EMAIL) !== false && strlen($v) <= 254,
            'url' => is_string($v) && filter_var($v, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $v) === 1,
            'date' => is_string($v) && strtotime($v) !== false,
            'alpha' => is_string($v) && preg_match('/^\pL+$/u', $v) === 1,
            'alpha_num' => is_string($v) && preg_match('/^[\pL\pN]+$/u', $v) === 1,
            'alpha_dash' => is_string($v) && preg_match('/^[\pL\pN_-]+$/u', $v) === 1,
            'uuid' => is_string($v) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $v) === 1,
            'min' => $this->size($v, $numeric) >= (float) $p[0],
            'max' => $this->size($v, $numeric) <= (float) $p[0],
            'between' => $this->size($v, $numeric) >= (float) $p[0] && $this->size($v, $numeric) <= (float) $p[1],
            'in' => in_array((string) $v, $p, true),
            'not_in' => !in_array((string) $v, $p, true),
            'regex' => is_string($v) && preg_match($p[0], $v) === 1,
            'confirmed' => $v === Arr::get($this->data, $field . '_confirmation'),
            'same' => $v === Arr::get($this->data, $p[0]),
            'different' => $v !== Arr::get($this->data, $p[0]),
            'unique' => $this->databaseCount($p, $v, $field, true) === 0,
            'exists' => $this->databaseCount($p, $v, $field, false) > 0,
            default => throw new \InvalidArgumentException("Unknown validation rule [{$rule}]."),
        };
    }

    /** Strings are measured by length, unless the field is also declared integer/numeric. */
    private function size(mixed $v, bool $numeric): float
    {
        return match (true) {
            is_array($v) => count($v),
            is_int($v) || is_float($v) => (float) $v,
            is_string($v) && $numeric && is_numeric($v) => (float) $v,
            is_string($v) => mb_strlen($v),
            default => 0.0,
        };
    }

    /** `unique:table,column,ignoreId,idColumn` / `exists:table,column` */
    private function databaseCount(array $p, mixed $value, string $field, bool $unique): int
    {
        if ($this->db === null) {
            throw new \LogicException('A database connection is required for unique/exists rules.');
        }
        $column = $p[1] ?? preg_replace('/^.*\./', '', $field);
        $q = $this->db->table($p[0])->useWritePdo()->where($column, '=', $value); // primary: replica lag must not allow duplicates
        if ($unique && isset($p[2]) && $p[2] !== '') {
            $q->where($p[3] ?? 'id', '!=', $p[2]);
        }
        return $q->count();
    }

    private function fail(string $field, string $rule, array $params): void
    {
        $template = $this->messages["{$field}.{$rule}"] ?? $this->messages[$rule] ?? self::MESSAGES[$rule] ?? "The :field is invalid.";
        $label = str_replace('_', ' ', $field);
        $message = str_replace(':field', $label, $template);
        foreach ($params as $i => $param) {
            $message = str_replace(':' . $i, (string) $param, $message);
        }
        $this->errors[$field][] = $message;
    }
}
