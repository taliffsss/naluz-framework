<?php

declare(strict_types=1);

namespace Naluz\Database;

use Naluz\Database\Orm\Model;
use Naluz\Support\Collection;
use Naluz\Support\Fake;

/**
 * Model factories for tests and seeders.
 *
 *   User::factory()->count(5)->create();
 *   Post::factory()->state(['published' => true])->create(['title' => 'Override']);
 *   Post::factory()->create(['user_id' => User::factory()])   // nested factories are created and their key used
 *
 * Factories use forceFill(): they are trusted code, so $fillable does not apply.
 *
 * @template TModel of Model
 */
abstract class Factory
{
    /** @var class-string<TModel> */
    protected string $model;
    private int $count = 1;
    private bool $many = false;
    /** @var list<array|\Closure> */
    private array $states = [];
    /** @var list<\Closure> */
    private array $afterCreating = [];

    /** @return array<string,mixed> default attributes */
    abstract public function definition(): array;

    protected function fake(): Fake
    {
        return Fake::instance();
    }

    public static function new(array $attributes = []): static
    {
        $factory = new static();
        return $attributes === [] ? $factory : $factory->state($attributes);
    }

    public function count(int $count): static
    {
        $clone = clone $this;
        $clone->count = max(0, $count);
        $clone->many = true;
        return $clone;
    }

    /** @param array<string,mixed>|\Closure(array):array $state */
    public function state(array|\Closure $state): static
    {
        $clone = clone $this;
        $clone->states[] = $state;
        return $clone;
    }

    public function afterCreating(\Closure $callback): static
    {
        $clone = clone $this;
        $clone->afterCreating[] = $callback;
        return $clone;
    }

    /** Attributes without building models. @return array<string,mixed> */
    public function raw(array $override = [], bool $persistRelations = false): array
    {
        $attrs = $this->definition();
        foreach ($this->states as $state) {
            $attrs = array_replace($attrs, $state instanceof \Closure ? $state($attrs) : $state);
        }
        $attrs = array_replace($attrs, $override);
        foreach ($attrs as $key => $value) {
            if ($value instanceof self) {
                $related = $persistRelations ? $value->create() : $value->make();
                $attrs[$key] = $related->getKey();
            } elseif ($value instanceof \Closure) {
                $attrs[$key] = $value($attrs);
            }
        }
        return $attrs;
    }

    /** @return TModel|Collection<int,TModel> */
    public function make(array $override = []): Model|Collection
    {
        return $this->build($override, false);
    }

    /** @return TModel|Collection<int,TModel> */
    public function create(array $override = []): Model|Collection
    {
        return $this->build($override, true);
    }

    private function build(array $override, bool $persist): Model|Collection
    {
        $models = [];
        for ($i = 0; $i < $this->count; $i++) {
            $model = (new $this->model())->forceFill($this->raw($override, $persist));
            if ($persist) {
                $model->save();
                foreach ($this->afterCreating as $callback) {
                    $callback($model);
                }
            }
            $models[] = $model;
        }
        return $this->many ? new Collection($models) : $models[0];
    }
}
