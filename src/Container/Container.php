<?php

declare(strict_types=1);

namespace Naluz\Container;

use Psr\Container\ContainerInterface;

/**
 * PSR-11 container with constructor auto-wiring, singletons and method injection.
 */
class Container implements ContainerInterface
{
    /** @var array<string,array{0:\Closure,1:bool}> */
    private array $bindings = [];
    /** @var array<string,object> */
    private array $instances = [];
    /** @var array<string,string> */
    private array $aliases = [];
    /** @var array<string,true> */
    private array $resolving = [];

    public function bind(string $abstract, \Closure|string|null $concrete = null, bool $shared = false): void
    {
        unset($this->instances[$abstract]);
        $concrete ??= $abstract;
        if (is_string($concrete)) {
            $class = $concrete;
            $concrete = fn (Container $c) => $class === $abstract ? $c->build($class) : $c->make($class);
        }
        $this->bindings[$abstract] = [$concrete, $shared];
    }

    public function singleton(string $abstract, \Closure|string|null $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
    }

    public function instance(string $abstract, object $instance): object
    {
        return $this->instances[$abstract] = $instance;
    }

    public function alias(string $alias, string $abstract): void
    {
        $this->aliases[$alias] = $abstract;
    }

    public function has(string $id): bool
    {
        $id = $this->aliases[$id] ?? $id;
        return isset($this->bindings[$id]) || isset($this->instances[$id]) || class_exists($id);
    }

    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new NotFoundException("No entry found for [{$id}].");
        }
        return $this->make($id);
    }

    /** @param array<string,mixed> $parameters named constructor overrides */
    public function make(string $abstract, array $parameters = []): mixed
    {
        $abstract = $this->aliases[$abstract] ?? $abstract;

        if (isset($this->instances[$abstract]) && $parameters === []) {
            return $this->instances[$abstract];
        }

        if (isset($this->bindings[$abstract])) {
            [$factory, $shared] = $this->bindings[$abstract];
            $object = $factory($this, $parameters);
            if ($shared && $parameters === []) {
                $this->instances[$abstract] = $object;
            }
            return $object;
        }

        return $this->build($abstract, $parameters);
    }

    /** @param array<string,mixed> $parameters */
    public function build(string $class, array $parameters = []): object
    {
        if (!class_exists($class) && !interface_exists($class)) {
            throw new NotFoundException("Class [{$class}] does not exist and has no binding.");
        }
        if (isset($this->resolving[$class])) {
            throw new ContainerException("Circular dependency detected while resolving [{$class}].");
        }
        $reflector = new \ReflectionClass($class);
        if (!$reflector->isInstantiable()) {
            throw new ContainerException("[{$class}] is not instantiable; bind it in the container.");
        }

        $this->resolving[$class] = true;
        try {
            $constructor = $reflector->getConstructor();
            $args = $constructor ? $this->resolveParameters($constructor, $parameters) : [];
            return $reflector->newInstanceArgs($args);
        } finally {
            unset($this->resolving[$class]);
        }
    }

    /**
     * Call any callable / [class, method] / "Class@method" with injected dependencies.
     *
     * @param array<string,mixed> $parameters named values (e.g. route parameters)
     */
    public function call(callable|array|string $callback, array $parameters = []): mixed
    {
        if (is_string($callback) && str_contains($callback, '@')) {
            $callback = explode('@', $callback, 2);
        }
        if (is_array($callback)) {
            [$target, $method] = $callback;
            $target = is_string($target) ? $this->make($target) : $target;
            $reflector = new \ReflectionMethod($target, $method);
            return $reflector->invokeArgs($target, $this->resolveParameters($reflector, $parameters));
        }
        $reflector = is_object($callback) && !$callback instanceof \Closure
            ? new \ReflectionMethod($callback, '__invoke')
            : new \ReflectionFunction(\Closure::fromCallable($callback));

        return $callback(...$this->resolveParameters($reflector, $parameters));
    }

    /**
     * @param array<string,mixed> $given
     * @return list<mixed>
     */
    private function resolveParameters(\ReflectionFunctionAbstract $function, array $given): array
    {
        $args = [];
        foreach ($function->getParameters() as $param) {
            $name = $param->getName();
            $type = $param->getType();

            if (array_key_exists($name, $given)) {
                $args[] = $this->coerce($given[$name], $type);
                continue;
            }
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                if ($this->has($type->getName()) || !$param->isDefaultValueAvailable()) {
                    try {
                        $args[] = $this->make($type->getName());
                        continue;
                    } catch (NotFoundException | ContainerException $e) {
                        if (!$param->isOptional() && !$type->allowsNull()) {
                            throw $e;
                        }
                    }
                }
            }
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } elseif ($type?->allowsNull()) {
                $args[] = null;
            } else {
                throw new ContainerException(sprintf(
                    'Unresolvable parameter $%s of %s::%s().',
                    $name,
                    $function instanceof \ReflectionMethod ? $function->class : 'function',
                    $function->getName()
                ));
            }
        }
        return $args;
    }

    /** Route/URL parameters arrive as strings; cast them to the declared scalar type. */
    private function coerce(mixed $value, ?\ReflectionType $type): mixed
    {
        if (!is_string($value) || !$type instanceof \ReflectionNamedType || !$type->isBuiltin()) {
            return $value;
        }
        return match ($type->getName()) {
            'int' => preg_match('/^-?\d+$/', $value) ? (int) $value : throw new \Naluz\Http\HttpException(404, 'Invalid parameter.'),
            'float' => is_numeric($value) ? (float) $value : throw new \Naluz\Http\HttpException(404, 'Invalid parameter.'),
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }
}
