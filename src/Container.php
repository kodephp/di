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

/**
 * 依赖注入容器
 * 
 * 高性能 PHP 8.1+ 依赖注入容器实现
 * 支持属性注入、生命周期管理、协程上下文隔离
 */
final class Container implements ContainerInterface
{
    /** @var array<string, Binding> 服务绑定 */
    private array $bindings = [];

    /** @var array<string, string> 服务别名 */
    private array $aliases = [];

    /** @var array<string, object> 已解析的单例实例 */
    private array $instances = [];

    /** @var array<string, true> 正在解析的服务（用于循环依赖检测） */
    private array $resolving = [];

    /** @var array<string, array<string, string|Closure>> 上下文绑定 */
    private array $contextual = [];

    /** @var array<string, Closure[]> 服务扩展器 */
    private array $extenders = [];

    /** @var array<string, Closure> 方法绑定 */
    private array $methodBindings = [];

    /** @var array<string, ReflectionClass> 反射缓存 */
    private static array $reflectionCache = [];

    /** @var bool kode/context 是否可用 */
    private static bool $contextAvailable = false;

    /** @var bool 是否已检查 context 可用性 */
    private static bool $contextChecked = false;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->registerSelf();
    }

    /**
     * 注册容器自身
     */
    private function registerSelf(): void
    {
        $this->instance(ContainerInterface::class, $this);
        $this->instance(self::class, $this);
        $this->instance('container', $this);
    }

    /**
     * 绑定服务到容器
     *
     * @param string $id 服务标识
     * @param Closure|string|null $concrete 具体实现
     * @param string $lifecycle 生命周期类型
     * @return Binding 绑定对象
     */
    public function bind(string $id, Closure|string|null $concrete = null, string $lifecycle = self::SINGLETON): Binding
    {
        $concrete ??= $id;

        $binding = new Binding($id, $concrete);
        $binding->setLifecycle($lifecycle);

        $this->bindings[$id] = $binding;
        unset($this->instances[$id]);

        return $binding;
    }

    /**
     * 绑定单例服务
     */
    public function singleton(string $id, Closure|string|null $concrete = null): Binding
    {
        return $this->bind($id, $concrete, self::SINGLETON);
    }

    /**
     * 绑定原型服务（每次获取创建新实例）
     */
    public function prototype(string $id, Closure|string|null $concrete = null): Binding
    {
        return $this->bind($id, $concrete, self::PROTOTYPE);
    }

    /**
     * 绑定懒加载服务
     */
    public function lazy(string $id, Closure|string|null $concrete = null): Binding
    {
        return $this->bind($id, $concrete, self::LAZY);
    }

    /**
     * 绑定上下文隔离服务（协程/Fiber间隔离）
     */
    public function contextual(string $id, Closure|string|null $concrete = null): Binding
    {
        return $this->bind($id, $concrete, self::CONTEXTUAL);
    }

    /**
     * 设置服务别名
     */
    public function alias(string $alias, string $id): void
    {
        $this->aliases[$alias] = $id;
    }

    /**
     * 扩展服务（在服务解析后执行回调）
     */
    public function extend(string $id, Closure $callback): void
    {
        $id = $this->resolveAlias($id);
        $this->extenders[$id][] = $callback;
    }

    /**
     * 开始上下文绑定
     */
    public function when(string $when): Definition
    {
        return new Definition($when);
    }

    /**
     * 指定需要的依赖
     */
    public function needs(string $needs): Definition
    {
        throw new \LogicException('必须先调用 when() 方法');
    }

    /**
     * 指定实现
     */
    public function give(string|Closure $implementation): void
    {
        throw new \LogicException('必须先调用 when() 和 needs() 方法');
    }

    /**
     * 注册已存在的实例
     */
    public function instance(string $id, object $instance): void
    {
        $binding = new Binding($id);
        $binding->setInstance($instance);
        $binding->setLifecycle(self::SINGLETON);

        $this->bindings[$id] = $binding;
        $this->instances[$id] = $instance;
    }

    /**
     * 获取服务（PSR-11）
     */
    public function get(string $id): mixed
    {
        return $this->resolve($id);
    }

    /**
     * 检查服务是否存在（PSR-11）
     */
    public function has(string $id): bool
    {
        $id = $this->resolveAlias($id);
        return isset($this->bindings[$id]) || class_exists($id);
    }

    /**
     * 检查服务是否已解析
     */
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

    /**
     * 移除服务绑定
     */
    public function forget(string $id): void
    {
        $id = $this->resolveAlias($id);
        unset($this->bindings[$id], $this->instances[$id]);
    }

    /**
     * 清空容器
     */
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

    /**
     * 获取所有绑定标识
     */
    public function getBindings(): array
    {
        return array_keys($this->bindings);
    }

    /**
     * 获取所有别名
     */
    public function getAliases(): array
    {
        return $this->aliases;
    }

    /**
     * 解析服务
     *
     * @param string $id 服务标识
     * @param array $parameters 构造参数
     * @return mixed 服务实例
     * @throws ContainerException 循环依赖异常
     * @throws ServiceNotFoundException 服务未找到异常
     */
    public function resolve(string $id, array $parameters = []): mixed
    {
        $id = $this->resolveAlias($id);

        // 循环依赖检测
        if (isset($this->resolving[$id])) {
            throw ContainerException::circularReference($id, array_keys($this->resolving));
        }

        // 返回已缓存的实例
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        $binding = $this->bindings[$id] ?? null;

        // 没有绑定，尝试自动解析
        if ($binding === null) {
            if (!class_exists($id)) {
                throw ServiceNotFoundException::create($id);
            }
            return $this->build($id, $parameters);
        }

        // 上下文隔离服务
        if ($binding->isContextual()) {
            return $this->resolveContextual($id, $binding, $parameters);
        }

        // 懒加载服务
        if ($binding->isLazy()) {
            return $this->createLazyProxy($id, $binding);
        }

        // 单例且已解析
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

    /**
     * 创建服务实例
     */
    public function make(string $id, array $parameters = []): mixed
    {
        return $this->resolve($id, $parameters);
    }

    /**
     * 调用方法并自动注入依赖
     */
    public function call(callable $callback, array $parameters = []): mixed
    {
        return $this->callMethod($callback, $parameters);
    }

    /**
     * 绑定方法
     */
    public function bindMethod(string $method, Closure $callback): void
    {
        $this->methodBindings[$method] = $callback;
    }

    /**
     * 调用方法绑定
     */
    public function callMethodBinding(string $method, object $instance): mixed
    {
        if (isset($this->methodBindings[$method])) {
            return $this->methodBindings[$method]($instance, $this);
        }

        return null;
    }

    /**
     * 重新绑定时执行回调
     */
    public function rebinding(string $id, Closure $callback): void
    {
        $this->extend($id, function ($instance, $container) use ($callback) {
            $callback($instance, $container);
            return $instance;
        });
    }

    /**
     * 解析时执行回调
     */
    public function resolving(string $id, Closure $callback): void
    {
        $this->extend($id, $callback);
    }

    /**
     * 解析后执行回调
     */
    public function afterResolving(string $id, Closure $callback): void
    {
        $this->extend($id, $callback);
    }

    /**
     * 解析别名
     */
    private function resolveAlias(string $id): string
    {
        while (isset($this->aliases[$id])) {
            $id = $this->aliases[$id];
        }
        return $id;
    }

    /**
     * 构建绑定实例
     */
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

    /**
     * 构建类实例
     */
    private function build(string $concrete, array $parameters = []): mixed
    {
        $reflector = $this->getReflector($concrete);

        if (!$reflector->isInstantiable()) {
            throw ContainerException::notInstantiable($concrete);
        }

        // 检测生命周期属性
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

    /**
     * 检测类上的生命周期属性
     */
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

    /**
     * 解析构造函数依赖
     */
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

    /**
     * 解析单个参数
     */
    private function resolveParameter(ReflectionParameter $parameter, string $class): mixed
    {
        $name = $parameter->getName();
        $type = $parameter->getType();

        // 检查 #[Inject] 属性
        $injectAttr = Attr::get($parameter, Inject::class);
        if ($injectAttr !== null) {
            $inject = $injectAttr->getInstance();
            $serviceId = $inject->id;

            if ($serviceId !== null) {
                return $this->resolve($serviceId);
            }
        }

        // 类型提示自动解析
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $typeHint = $type->getName();

            // 上下文绑定
            $contextualImpl = $this->contextual[$class][$typeHint] ?? null;
            if ($contextualImpl !== null) {
                return $contextualImpl instanceof Closure
                    ? $contextualImpl($this)
                    : $this->resolve($contextualImpl);
            }

            return $this->resolve($typeHint);
        }

        // 默认值
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        // 可变参数
        if ($parameter->isVariadic()) {
            return [];
        }

        // 允许 null
        if ($type === null || $type->allowsNull()) {
            return null;
        }

        throw ContainerException::unresolvedParameter($name, $class);
    }

    /**
     * 属性注入
     */
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

    /**
     * 应用扩展器
     */
    private function applyExtenders(string $id, object $instance): object
    {
        $id = $this->resolveAlias($id);
        $extenders = $this->extenders[$id] ?? [];

        foreach ($extenders as $extender) {
            $instance = $extender($instance, $this);
        }

        return $instance;
    }

    /**
     * 调用方法并注入依赖
     */
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

    /**
     * 解析函数依赖
     */
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

    /**
     * 解析上下文隔离服务
     * 
     * 当 kode/context 可用时，使用其进行协程间隔离
     */
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

    /**
     * 创建懒加载代理
     * 
     * PHP 8.4+ 使用原生懒加载对象
     * PHP 8.1-8.3 使用匿名类代理
     */
    private function createLazyProxy(string $id, Binding $binding): mixed
    {
        if (PhpVersion::supportsLazyObjects()) {
            return $this->createNativeLazyProxy($id, $binding);
        }

        return $this->createLegacyLazyProxy($binding);
    }

    /**
     * 创建原生懒加载代理 (PHP 8.4+)
     */
    private function createNativeLazyProxy(string $id, Binding $binding): mixed
    {
        $concrete = $binding->getConcrete();
        $className = is_string($concrete) ? $concrete : \stdClass::class;

        $reflector = $this->getReflector($className);

        return $reflector->newLazyProxy(function () use ($binding) {
            return $this->buildBinding($binding);
        });
    }

    /**
     * 创建传统懒加载代理 (PHP 8.1-8.3)
     */
    private function createLegacyLazyProxy(Binding $binding): mixed
    {
        $container = $this;
        return new class($container, $binding) {
            private ?object $instance = null;

            private Container $container;

            private Binding $binding;

            public function __construct(Container $container, Binding $binding)
            {
                $this->container = $container;
                $this->binding = $binding;
            }

            public function __call(string $method, array $arguments): mixed
            {
                if ($this->instance === null) {
                    $this->instance = $this->container->buildBindingPublic($this->binding);
                }

                return $this->instance->$method(...$arguments);
            }

            public function __get(string $name): mixed
            {
                if ($this->instance === null) {
                    $this->instance = $this->container->buildBindingPublic($this->binding);
                }

                return $this->instance->$name;
            }

            public function __set(string $name, mixed $value): void
            {
                if ($this->instance === null) {
                    $this->instance = $this->container->buildBindingPublic($this->binding);
                }

                $this->instance->$name = $value;
            }
        };
    }

    /**
     * 公开的构建绑定方法（供懒加载代理使用）
     */
    public function buildBindingPublic(Binding $binding): mixed
    {
        return $this->buildBinding($binding);
    }

    /**
     * 检查 kode/context 是否可用
     */
    private function isContextAvailable(): bool
    {
        if (!self::$contextChecked) {
            self::$contextAvailable = class_exists('Kode\Context\Context');
            self::$contextChecked = true;
        }

        return self::$contextAvailable;
    }

    /**
     * 获取反射类（带缓存）
     */
    private function getReflector(string $class): ReflectionClass
    {
        if (!isset(self::$reflectionCache[$class])) {
            self::$reflectionCache[$class] = new ReflectionClass($class);
        }

        return self::$reflectionCache[$class];
    }

    /**
     * 清除所有缓存
     */
    public static function clearCache(): void
    {
        self::$reflectionCache = [];
        self::$contextChecked = false;
        self::$contextAvailable = false;
    }

    /**
     * 添加上下文绑定
     */
    public function addContextualBinding(string $when, string $needs, string|Closure $give): void
    {
        $this->contextual[$when][$needs] = $give;
    }

    /**
     * 给服务打标签
     */
    public function tag(string $tag, array $ids): void
    {
        foreach ($ids as $id) {
            $id = $this->resolveAlias($id);

            if (isset($this->bindings[$id])) {
                $this->bindings[$id]->tag($tag);
            }
        }
    }

    /**
     * 获取所有带指定标签的服务
     */
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

    /**
     * 创建工厂闭包
     */
    public function factory(string $id): Closure
    {
        return fn(array $parameters = []) => $this->make($id, $parameters);
    }

    /**
     * 环境条件绑定
     */
    public function environment(string|array $environments, Closure $callback): void
    {
        $environments = (array) $environments;

        $env = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'production';

        if (in_array($env, $environments, true)) {
            $callback($this);
        }
    }

    /**
     * 条件绑定
     */
    public function if(string $condition, Closure $true, ?Closure $false = null): void
    {
        if ($condition) {
            $true($this);
        } elseif ($false !== null) {
            $false($this);
        }
    }
}
