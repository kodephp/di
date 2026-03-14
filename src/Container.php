<?php

declare(strict_types=1);

namespace Kode\DI;

use Closure;
use ReflectionClass;
use ReflectionParameter;
use ReflectionNamedType;
use Kode\Attributes\Attr;
use Kode\DI\Attributes\Inject;
use Kode\DI\Attributes\Autowire;
use Kode\DI\Attributes\Singleton;
use Kode\DI\Attributes\Prototype;
use Kode\DI\Attributes\Contextual as ContextualAttr;
use Kode\DI\Contract\ContainerInterface;
use Kode\DI\Exception\ContainerException;
use Kode\DI\Exception\ServiceNotFoundException;

final class Container implements ContainerInterface
{
    private array $bindings = [];

    private array $aliases = [];

    private array $instances = [];

    private array $resolving = [];

    private array $contextual = [];

    private array $extenders = [];

    private static array $reflectionCache = [];

    public function __construct()
    {
        $this->registerSelf();
    }

    private function registerSelf(): void
    {
        $binding = new Binding(ContainerInterface::class);
        $binding->setInstance($this);
        $binding->setLifecycle(self::SINGLETON);
        $this->bindings[ContainerInterface::class] = $binding;
        $this->instances[ContainerInterface::class] = $this;

        $bindingSelf = new Binding(self::class);
        $bindingSelf->setInstance($this);
        $bindingSelf->setLifecycle(self::SINGLETON);
        $this->bindings[self::class] = $bindingSelf;
        $this->instances[self::class] = $this;
    }

    public function bind(string $id, Closure|string|null $concrete = null, string $lifecycle = self::SINGLETON): Binding
    {
        $concrete ??= $id;

        $binding = new Binding($id, $concrete);
        $binding->setLifecycle($lifecycle);

        $this->bindings[$id] = $binding;
        unset($this->instances[$id]);

        return $binding;
    }

    public function singleton(string $id, Closure|string|null $concrete = null): Binding
    {
        return $this->bind($id, $concrete, self::SINGLETON);
    }

    public function prototype(string $id, Closure|string|null $concrete = null): Binding
    {
        return $this->bind($id, $concrete, self::PROTOTYPE);
    }

    public function lazy(string $id, Closure|string|null $concrete = null): Binding
    {
        return $this->bind($id, $concrete, self::LAZY);
    }

    public function contextual(string $id, Closure|string|null $concrete = null): Binding
    {
        return $this->bind($id, $concrete, self::CONTEXTUAL);
    }

    public function alias(string $alias, string $id): void
    {
        $this->aliases[$alias] = $id;
    }

    public function extend(string $id, Closure $callback): void
    {
        $id = $this->resolveAlias($id);
        $this->extenders[$id][] = $callback;
    }

    public function when(string $when): Definition
    {
        return new Definition($when);
    }

    public function needs(string $needs): Definition
    {
        throw new \LogicException('必须先调用 when() 方法');
    }

    public function give(string|Closure $implementation): void
    {
        throw new \LogicException('必须先调用 when() 和 needs() 方法');
    }

    public function instance(string $id, object $instance): void
    {
        $binding = new Binding($id);
        $binding->setInstance($instance);
        $binding->setLifecycle(self::SINGLETON);

        $this->bindings[$id] = $binding;
        $this->instances[$id] = $instance;
    }

    public function get(string $id): mixed
    {
        return $this->resolve($id);
    }

    public function has(string $id): bool
    {
        $id = $this->resolveAlias($id);
        return isset($this->bindings[$id]) || class_exists($id);
    }

    public function resolved(string $id): bool
    {
        $id = $this->resolveAlias($id);
        return isset($this->instances[$id]) ||
               (isset($this->bindings[$id]) && $this->bindings[$id]->isResolved());
    }

    public function forget(string $id): void
    {
        $id = $this->resolveAlias($id);
        unset($this->bindings[$id], $this->instances[$id]);
    }

    public function flush(): void
    {
        $this->bindings = [];
        $this->aliases = [];
        $this->instances = [];
        $this->contextual = [];
        $this->extenders = [];
        $this->resolving = [];
    }

    public function getBindings(): array
    {
        return array_keys($this->bindings);
    }

    public function getAliases(): array
    {
        return $this->aliases;
    }

    public function resolve(string $id, array $parameters = []): mixed
    {
        $id = $this->resolveAlias($id);

        if (isset($this->resolving[$id])) {
            throw ContainerException::circularReference($id, array_keys($this->resolving));
        }

        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        $binding = $this->bindings[$id] ?? null;

        if ($binding === null) {
            if (!class_exists($id)) {
                throw ServiceNotFoundException::create($id);
            }
            return $this->build($id, $parameters);
        }

        if ($binding->isSingleton() && $binding->isResolved()) {
            return $binding->getInstance();
        }

        $this->resolving[$id] = true;

        try {
            $instance = $this->buildBinding($binding, $parameters);

            if ($binding->isSingleton()) {
                $binding->setInstance($instance);
                $this->instances[$id] = $instance;
            }

            $instance = $this->applyExtenders($id, $instance);
            unset($this->resolving[$id]);

            return $instance;
        } finally {
            unset($this->resolving[$id]);
        }
    }

    public function make(string $id, array $parameters = []): mixed
    {
        return $this->resolve($id, $parameters);
    }

    public function call(callable $callback, array $parameters = []): mixed
    {
        return $this->callMethod($callback, $parameters);
    }

    private function resolveAlias(string $id): string
    {
        while (isset($this->aliases[$id])) {
            $id = $this->aliases[$id];
        }
        return $id;
    }

    private function buildBinding(Binding $binding, array $parameters = []): mixed
    {
        $concrete = $binding->getConcrete();

        if ($concrete instanceof Closure) {
            return $concrete($this, $parameters);
        }

        if (is_string($concrete)) {
            return $this->build($concrete, $parameters);
        }

        throw ContainerException::invalidBinding($binding->getId(), $concrete);
    }

    private function build(string $concrete, array $parameters = []): mixed
    {
        $reflector = $this->getReflector($concrete);

        if (!$reflector->isInstantiable()) {
            throw ContainerException::notInstantiable($concrete);
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            $instance = new $concrete();
            return $this->injectProperties($instance, $reflector);
        }

        $dependencies = $this->resolveDependencies(
            $constructor->getParameters(),
            $concrete,
            $parameters
        );

        $instance = $reflector->newInstanceArgs($dependencies);
        return $this->injectProperties($instance, $reflector);
    }

    private function resolveDependencies(
        array $parameters,
        string $class,
        array $passed = []
    ): array {
        $resolved = [];

        foreach ($parameters as $parameter) {
            $name = $parameter->getName();

            if (array_key_exists($name, $passed)) {
                $resolved[] = $passed[$name];
                continue;
            }

            $resolved[] = $this->resolveParameter($parameter, $class);
        }

        return $resolved;
    }

    private function resolveParameter(ReflectionParameter $parameter, string $class): mixed
    {
        $name = $parameter->getName();
        $type = $parameter->getType();

        $injectAttr = Attr::get($parameter, Inject::class);
        if ($injectAttr !== null) {
            $inject = $injectAttr->getInstance();
            $serviceId = $inject->id;

            if ($serviceId !== null) {
                return $this->resolve($serviceId);
            }
        }

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $typeHint = $type->getName();

            $contextualImpl = $this->contextual[$class][$typeHint] ?? null;
            if ($contextualImpl !== null) {
                return $contextualImpl instanceof Closure
                    ? $contextualImpl($this)
                    : $this->resolve($contextualImpl);
            }

            return $this->resolve($typeHint);
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->isVariadic()) {
            return [];
        }

        if ($type === null || $type->allowsNull()) {
            return null;
        }

        throw ContainerException::unresolvedParameter($name, $class);
    }

    private function injectProperties(object $instance, ReflectionClass $reflector): object
    {
        foreach ($reflector->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $injectAttr = Attr::get($property, Inject::class);
            if ($injectAttr === null) {
                continue;
            }

            $inject = $injectAttr->getInstance();
            $serviceId = $inject->id;

            if ($serviceId === null) {
                $type = $property->getType();
                if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                    $serviceId = $type->getName();
                }
            }

            if ($serviceId === null) {
                if ($inject->required) {
                    throw ContainerException::unresolvedParameter(
                        $property->getName(),
                        $reflector->getName()
                    );
                }
                continue;
            }

            $value = $this->resolve($serviceId);

            if (!$property->isPublic()) {
                $property->setAccessible(true);
            }

            $property->setValue($instance, $value);
        }

        return $instance;
    }

    private function applyExtenders(string $id, object $instance): object
    {
        $id = $this->resolveAlias($id);
        $extenders = $this->extenders[$id] ?? [];

        foreach ($extenders as $extender) {
            $instance = $extender($instance, $this);
        }

        return $instance;
    }

    private function callMethod(callable $callback, array $parameters = []): mixed
    {
        if (is_array($callback)) {
            [$class, $method] = $callback;
            $reflection = new \ReflectionMethod($class, $method);
            $dependencies = $this->resolveDependencies(
                $reflection->getParameters(),
                is_string($class) ? $class : get_class($class),
                $parameters
            );

            if (is_string($class)) {
                $class = $this->resolve($class);
            }

            return $reflection->invokeArgs($class, $dependencies);
        }

        if ($callback instanceof Closure || is_string($callback)) {
            $reflection = new \ReflectionFunction($callback);
            $dependencies = $this->resolveFunctionDependencies(
                $reflection->getParameters(),
                $parameters
            );

            return $reflection->invokeArgs($dependencies);
        }

        return $callback(...$parameters);
    }

    private function resolveFunctionDependencies(array $parameters, array $passed = []): array
    {
        $resolved = [];

        foreach ($parameters as $parameter) {
            $name = $parameter->getName();

            if (array_key_exists($name, $passed)) {
                $resolved[] = $passed[$name];
                continue;
            }

            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $resolved[] = $this->resolve($type->getName());
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $resolved[] = $parameter->getDefaultValue();
                continue;
            }

            if ($parameter->isVariadic()) {
                $resolved[] = [];
                continue;
            }

            $resolved[] = null;
        }

        return $resolved;
    }

    private function getReflector(string $class): ReflectionClass
    {
        if (!isset(self::$reflectionCache[$class])) {
            self::$reflectionCache[$class] = new ReflectionClass($class);
        }

        return self::$reflectionCache[$class];
    }

    public static function clearCache(): void
    {
        self::$reflectionCache = [];
    }

    public function addContextualBinding(string $when, string $needs, string|Closure $give): void
    {
        $this->contextual[$when][$needs] = $give;
    }

    public function tag(string $tag, array $ids): void
    {
        foreach ($ids as $id) {
            $id = $this->resolveAlias($id);

            if (isset($this->bindings[$id])) {
                $this->bindings[$id]->tag($tag);
            }
        }
    }

    public function tagged(string $tag): array
    {
        $resolved = [];

        foreach ($this->bindings as $id => $binding) {
            if ($binding->hasTag($tag)) {
                $resolved[$id] = $this->resolve($id);
            }
        }

        return $resolved;
    }
}
