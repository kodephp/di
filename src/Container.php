<?php

declare(strict_types=1);

namespace Kode\DI;

use Closure;
use ReflectionClass;
use ReflectionMethod;
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

    private array $methodBindings = [];

    private static array $reflectionCache = [];

    private static bool $contextAvailable = false;

    private static bool $contextChecked = false;

    public function __construct()
    {
        $this->registerSelf();
    }

    private function registerSelf(): void
    {
        $this->instance(ContainerInterface::class, $this);
        $this->instance(self::class, $this);
        $this->instance('container', $this);
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

        if (isset($this->instances[$id])) {
            return true;
        }

        if (isset($this->bindings[$id])) {
            return $this->bindings[$id]->isResolved();
        }

        return false;
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
        $this->methodBindings = [];
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

        if ($binding->isContextual()) {
            return $this->resolveContextual($id, $binding, $parameters);
        }

        if ($binding->isLazy()) {
            return $this->createLazyProxy($id, $binding);
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

    public function bindMethod(string $method, Closure $callback): void
    {
        $this->methodBindings[$method] = $callback;
    }

    public function callMethodBinding(string $method, object $instance): mixed
    {
        if (isset($this->methodBindings[$method])) {
            return $this->methodBindings[$method]($instance, $this);
        }

        return null;
    }

    public function rebinding(string $id, Closure $callback): void
    {
        $this->extend($id, function ($instance, $container) use ($callback) {
            $callback($instance, $container);
            return $instance;
        });
    }

    public function resolving(string $id, Closure $callback): void
    {
        $this->extend($id, $callback);
    }

    public function afterResolving(string $id, Closure $callback): void
    {
        $this->extend($id, $callback);
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

        $this->detectLifecycleAttribute($concrete, $reflector);

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

    private function detectLifecycleAttribute(string $concrete, ReflectionClass $reflector): void
    {
        if (isset($this->bindings[$concrete])) {
            return;
        }

        if (Attr::has($reflector, Singleton::class)) {
            $this->singleton($concrete);
        } elseif (Attr::has($reflector, Prototype::class)) {
            $this->prototype($concrete);
        } elseif (Attr::has($reflector, ContextualAttr::class)) {
            $this->contextual($concrete);
        }
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

            if (is_string($class)) {
                $class = $this->resolve($class);
            }

            $reflection = new ReflectionMethod($class, $method);
            $dependencies = $this->resolveDependencies(
                $reflection->getParameters(),
                is_string($callback[0]) ? $callback[0] : get_class($class),
                $parameters
            );

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

    private function resolveContextual(string $id, Binding $binding, array $parameters): mixed
    {
        if ($this->isContextAvailable()) {
            $contextClass = 'Kode\Context\Context';
            $contextKey = 'di.contextual.' . $id;

            if ($contextClass::has($contextKey)) {
                return $contextClass::get($contextKey);
            }

            $instance = $this->buildBinding($binding, $parameters);
            $contextClass::set($contextKey, $instance);

            return $instance;
        }

        return $this->buildBinding($binding, $parameters);
    }

    private function createLazyProxy(string $id, Binding $binding): mixed
    {
        return new class($this, $binding) {
            private ?object $instance = null;

            public function __construct(
                private readonly Container $container,
                private readonly Binding $binding
            ) {}

            public function __call(string $method, array $arguments): mixed
            {
                if ($this->instance === null) {
                    $this->instance = $this->container->buildBinding($this->binding);
                }

                return $this->instance->$method(...$arguments);
            }

            public function __get(string $name): mixed
            {
                if ($this->instance === null) {
                    $this->instance = $this->container->buildBinding($this->binding);
                }

                return $this->instance->$name;
            }

            public function __set(string $name, mixed $value): void
            {
                if ($this->instance === null) {
                    $this->instance = $this->container->buildBinding($this->binding);
                }

                $this->instance->$name = $value;
            }
        };
    }

    private function isContextAvailable(): bool
    {
        if (!self::$contextChecked) {
            self::$contextAvailable = class_exists('Kode\Context\Context');
            self::$contextChecked = true;
        }

        return self::$contextAvailable;
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
        self::$contextChecked = false;
        self::$contextAvailable = false;
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

    public function factory(string $id): Closure
    {
        return fn(array $parameters = []) => $this->make($id, $parameters);
    }

    public function environment(string|array $environments, Closure $callback): void
    {
        $environments = (array) $environments;

        $env = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'production';

        if (in_array($env, $environments, true)) {
            $callback($this);
        }
    }

    public function if(string $condition, Closure $true, ?Closure $false = null): void
    {
        if ($condition) {
            $true($this);
        } elseif ($false !== null) {
            $false($this);
        }
    }
}
