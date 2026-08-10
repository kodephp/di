<?php

declare(strict_types=1);

namespace Kode\DI;

use Closure;
use ReflectionClass;
use ReflectionMethod;
use ReflectionFunction;
use ReflectionParameter;
use ReflectionNamedType;
use ReflectionUnionType;
use ReflectionIntersectionType;
use ReflectionType;
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
 * 高性能 PHP 8.3+ 依赖注入容器实现
 * 支持属性注入、生命周期管理、协程上下文隔离，兼容 PSR-11
 * 并实现 \ArrayAccess 以原生数组语法访问服务。
 *
 * @implements \ArrayAccess<mixed, mixed>
 */
final class Container implements ContainerInterface, \ArrayAccess
{
    /** 闭包/函数依赖解析时使用的消费者上下文标识 */
    private const CLOSURE_CONTEXT = '{closure}';

    /** @var array<string, Binding> 服务绑定 */
    private array $bindings = [];

    /** @var array<string, string> 服务别名 */
    private array $aliases = [];

    /** @var array<string, mixed> 已解析的实例（单例 / 懒加载代理 / 实例绑定 / 标量单例） */
    private array $instances = [];

    /** @var array<string, true> 正在解析的服务（用于循环依赖检测） */
    private array $resolving = [];

    /** @var array<string, array<string, string|Closure>> 上下文绑定 */
    private array $contextual = [];

    /** @var array<string, Closure[]> 服务扩展器（可替换实例） */
    private array $extenders = [];

    /** @var array<string, Closure[]> 解析回调（观察者，不改变实例） */
    private array $resolvingCallbacks = [];

    /** @var array<string, Closure[]> 解析后回调（观察者，不改变实例） */
    private array $afterResolvingCallbacks = [];

    /** @var array<string, true> 已尝试过延迟提供者加载的标识（防止重入死循环） */
    private array $deferredAttempted = [];

    /** @var array<string, Closure> 方法绑定 */
    private array $methodBindings = [];

    /** @var ServiceProviderRegistry|null 服务提供者注册表 */
    private ?ServiceProviderRegistry $providerRegistry = null;

    /** @var Definition|null 上下文中待完成的上下文绑定构造器 */
    private ?Definition $contextualBuilder = null;

    /** @var array<string, ReflectionClass<object>> 反射缓存（按类名共享，跨容器安全） */
    private static array $reflectionCache = [];

    /** @var bool kode/context 是否可用 */
    private static bool $contextAvailable = false;

    /** @var bool 是否已检查 context 可用性 */
    private static bool $contextChecked = false;

    /** @var bool 是否已冻结（冻结后禁止运行时增删绑定） */
    private bool $frozen = false;

    /** @var bool 是否自动解析接口/抽象类的具体实现 */
    private bool $autoResolveImplementations = true;

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
     * 冻结态断言：容器冻结后禁止任何变更操作
     *
     * @throws \LogicException 若容器已冻结
     */
    private function assertNotFrozen(string $operation): void
    {
        if ($this->frozen) {
            throw new \LogicException("容器已冻结，禁止{$operation}");
        }
    }

    /**
     * 绑定服务到容器
     *
     * @param string $id 服务标识
     * @param Closure|string|null $concrete 具体实现
     * @param string $lifecycle 生命周期类型
     * @return Binding 绑定对象
     */
    #[\Override]
    public function bind(string $id, Closure|string|null $concrete = null, string $lifecycle = self::SINGLETON): Binding
    {
        $this->assertNotFrozen("绑定服务：{$id}");

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
    #[\Override]
    public function singleton(string $id, Closure|string|null $concrete = null): Binding
    {
        return $this->bind($id, $concrete, self::SINGLETON);
    }

    /**
     * 绑定原型服务（每次获取创建新实例）
     */
    #[\Override]
    public function prototype(string $id, Closure|string|null $concrete = null): Binding
    {
        return $this->bind($id, $concrete, self::PROTOTYPE);
    }

    /**
     * 绑定懒加载服务
     */
    #[\Override]
    public function lazy(string $id, Closure|string|null $concrete = null): Binding
    {
        return $this->bind($id, $concrete, self::LAZY);
    }

    /**
     * 绑定上下文隔离服务（协程/Fiber间隔离）
     */
    #[\Override]
    public function contextual(string $id, Closure|string|null $concrete = null): Binding
    {
        return $this->bind($id, $concrete, self::CONTEXTUAL);
    }

    /**
     * 仅在服务尚未绑定时绑定（幂等注册，避免重复绑定报错）
     *
     * @return Binding 既有或新建的绑定
     */
    #[\Override]
    public function bindIf(string $id, Closure|string|null $concrete = null, string $lifecycle = self::SINGLETON): Binding
    {
        $resolved = $this->resolveAlias($id);

        if (isset($this->bindings[$resolved])) {
            return $this->bindings[$resolved];
        }

        return $this->bind($id, $concrete, $lifecycle);
    }

    /**
     * 幂等单例绑定
     */
    #[\Override]
    public function singletonIf(string $id, Closure|string|null $concrete = null): Binding
    {
        return $this->bindIf($id, $concrete, self::SINGLETON);
    }

    /**
     * 幂等实例绑定
     */
    #[\Override]
    public function instanceIf(string $id, object $instance): void
    {
        $resolved = $this->resolveAlias($id);

        if (isset($this->bindings[$resolved])) {
            return;
        }

        $this->instance($id, $instance);
    }

    /**
     * 检查服务是否已绑定（含别名解析）
     */
    #[\Override]
    public function bound(string $id): bool
    {
        return isset($this->bindings[$this->resolveAlias($id)]);
    }

    /**
     * 设置服务别名
     */
    #[\Override]
    public function alias(string $alias, string $id): void
    {
        $this->assertNotFrozen("设置别名：{$alias} -> {$id}");

        $this->aliases[$alias] = $id;
    }

    /**
     * 扩展服务（在服务解析后执行回调）
     */
    #[\Override]
    public function extend(string $id, Closure $callback): void
    {
        $id = $this->resolveAlias($id);
        $this->assertNotFrozen("扩展服务：{$id}");

        $this->extenders[$id][] = $callback;
    }

    /**
     * 开始上下文绑定
     */
    #[\Override]
    public function when(string $when): Definition
    {
        $this->contextualBuilder = new Definition($when, $this);

        return $this->contextualBuilder;
    }

    /**
     * 指定需要的依赖（上下文绑定链式调用）
     *
     * @throws \LogicException 若未先调用 when()
     */
    #[\Override]
    public function needs(string $needs): Definition
    {
        if ($this->contextualBuilder === null) {
            throw new \LogicException('必须先调用 when() 方法');
        }

        return $this->contextualBuilder->needs($needs);
    }

    /**
     * 指定实现（上下文绑定链式调用）
     *
     * @throws \LogicException 若未先调用 when() 和 needs()
     */
    #[\Override]
    public function give(string|Closure $implementation): void
    {
        if ($this->contextualBuilder === null) {
            throw new \LogicException('必须先调用 when() 和 needs() 方法');
        }

        $builder = $this->contextualBuilder;
        $this->contextualBuilder = null;
        $builder->give($implementation);
    }

    /**
     * 注册已存在的实例
     */
    #[\Override]
    public function instance(string $id, object $instance): void
    {
        $this->assertNotFrozen("注册实例：{$id}");

        $binding = new Binding($id);
        $binding->setInstance($instance);
        $binding->setLifecycle(self::SINGLETON);
        $binding->markInstance();

        // 与 resolve() 产生的单例保持一致：经过扩展器并触发解析回调，
        // 使手动注册的实例同样可被观察与装饰。自注册（容器自身）无扩展器/回调，无副作用。
        $instance = $this->applyExtenders($id, $instance);

        $binding->setInstance($instance);
        $this->bindings[$id] = $binding;
        $this->instances[$id] = $instance;
    }

    /**
     * 获取服务（PSR-11）
     */
    #[\Override]
    public function get(string $id): mixed
    {
        return $this->resolve($id);
    }

    /**
     * 检查服务是否存在（PSR-11）
     */
    #[\Override]
    public function has(string $id): bool
    {
        $id = $this->resolveAlias($id);

        if (isset($this->bindings[$id]) || class_exists($id)) {
            return true;
        }

        return $this->providerRegistry !== null && $this->providerRegistry->hasDeferred($id);
    }

    /**
     * 检查服务是否已解析
     */
    #[\Override]
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
    #[\Override]
    public function forget(string $id): void
    {
        $id = $this->resolveAlias($id);
        $this->assertNotFrozen("移除绑定：{$id}");

        unset(
            $this->bindings[$id],
            $this->instances[$id],
            $this->extenders[$id],
            $this->resolvingCallbacks[$id],
            $this->afterResolvingCallbacks[$id],
            $this->methodBindings[$id],
            $this->contextual[$id]
        );
    }

    /**
     * 清空容器
     */
    #[\Override]
    public function flush(): void
    {
        $this->assertNotFrozen('清空容器');

        $this->bindings = [];
        $this->aliases = [];
        $this->instances = [];
        $this->contextual = [];
        $this->extenders = [];
        $this->resolvingCallbacks = [];
        $this->afterResolvingCallbacks = [];
        $this->resolving = [];
        $this->methodBindings = [];
        $this->providerRegistry = null;
        $this->contextualBuilder = null;
    }

    /**
     * 获取所有绑定标识
     *
     * @return array<int, string>
     */
    #[\Override]
    public function getBindings(): array
    {
        return array_keys($this->bindings);
    }

    /**
     * 获取所有别名
     *
     * @return array<string, string>
     */
    #[\Override]
    public function getAliases(): array
    {
        return $this->aliases;
    }

    /**
     * 解析服务
     *
     * @param string $id 服务标识
     * @param array<string, mixed> $parameters 构造参数
     * @return mixed 服务实例
     * @throws ContainerException 循环依赖异常
     * @throws ServiceNotFoundException 服务未找到异常
     */
    #[\Override]
    public function resolve(string $id, array $parameters = []): mixed
    {
        $id = $this->resolveAlias($id);

        // 循环依赖检测
        if (isset($this->resolving[$id])) {
            throw ContainerException::circularReference($id, array_keys($this->resolving));
        }

        // 返回已缓存的实例（单例 / 懒加载代理 / 实例绑定）
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        $binding = $this->bindings[$id] ?? null;

        // 没有绑定，尝试自动解析
        if ($binding === null) {
            // 接口/抽象类自动定位具体实现（可通过 setAutoResolveImplementations 关闭）。
            // 注意：抽象类 class_exists 为 true 但不可实例化，必须在此（非 !class_exists 内）尝试。
            if ($this->autoResolveImplementations) {
                $impl = $this->resolveImplementationClass($id);
                if ($impl !== null) {
                    // 仍以原始 id（接口/抽象类）为粒度触发扩展器与解析回调，
                    // 保证注册在接口 id 上的观察者/装饰器与解析出的实现类一致触发。
                    $instance = $this->resolve($impl, $parameters);

                    return $this->applyExtenders($id, $instance);
                }
            }

            if (!class_exists($id)) {
                // 尝试通过延迟服务提供者加载（仅重入一次，防止提供者未真正绑定导致死循环）
                if (
                    $this->providerRegistry !== null
                    && !isset($this->deferredAttempted[$id])
                    && $this->providerRegistry->resolveDeferred($id)
                ) {
                    $this->deferredAttempted[$id] = true;

                    try {
                        return $this->resolve($id, $parameters);
                    } finally {
                        unset($this->deferredAttempted[$id]);
                    }
                }

                throw ServiceNotFoundException::withSuggestions($id, $this->suggestId($id));
            }

            // 检测类上的生命周期属性，命中后重新进入解析以享受缓存
            if ($this->detectLifecycleAttribute($id)) {
                return $this->resolve($id, $parameters);
            }

            // 自动解析同样需要循环依赖守卫，否则未绑定类互相依赖会无限递归
            $this->resolving[$id] = true;

            try {
                // 自动解析的实例同样要经过扩展器与解析回调，保证行为一致
                return $this->applyExtenders($id, $this->build($id, $parameters));
            } finally {
                unset($this->resolving[$id]);
            }
        }

        // 上下文隔离服务
        if ($binding->isContextual()) {
            return $this->resolveContextual($id, $binding, $parameters);
        }

        // 懒加载服务（代理只创建一次）
        if ($binding->isLazy()) {
            $proxy = $this->createLazyProxy($id, $binding);
            $this->instances[$id] = $proxy;

            return $proxy;
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
     *
     * @param array<string, mixed> $parameters
     */
    #[\Override]
    public function make(string $id, array $parameters = []): mixed
    {
        return $this->resolve($id, $parameters);
    }

    /**
     * 调用方法并自动注入依赖
     *
     * @param callable|array{0: object|string, 1: string} $callback
     * @param array<string, mixed> $parameters
     */
    #[\Override]
    public function call(callable|array $callback, array $parameters = []): mixed
    {
        return $this->callMethod($callback, $parameters);
    }

    /**
     * 绑定方法（在 call() 调用指定类方法时优先使用绑定闭包）
     */
    public function bindMethod(string $method, Closure $callback): void
    {
        $this->assertNotFrozen("绑定方法：{$method}");

        $this->methodBindings[$this->normalizeMethodKey($method)] = $callback;
    }

    /**
     * 调用方法绑定
     */
    public function callMethodBinding(string $method, object $instance): mixed
    {
        $key = $this->normalizeMethodKey($method);

        if (isset($this->methodBindings[$key])) {
            return $this->methodBindings[$key]($instance, $this);
        }

        return null;
    }

    /**
     * 重新绑定时执行回调（观察者语义，不改变实例）
     */
    public function rebinding(string $id, Closure $callback): void
    {
        $this->resolving($id, $callback);
    }

    /**
     * 解析时执行回调（观察者语义，回调返回值被忽略）
     */
    public function resolving(string $id, Closure $callback): void
    {
        $this->resolvingCallbacks[$this->resolveAlias($id)][] = $callback;
    }

    /**
     * 解析后执行回调（在所有 resolving 回调之后触发）
     */
    public function afterResolving(string $id, Closure $callback): void
    {
        $this->afterResolvingCallbacks[$this->resolveAlias($id)][] = $callback;
    }

    /**
     * 注册服务提供者（类字符串或实例）
     */
    #[\Override]
    public function registerProvider(ServiceProvider|string $provider): void
    {
        $this->assertNotFrozen('注册服务提供者');

        $this->providers()->register($provider);
    }

    /**
     * 批量注册服务提供者
     *
     * @param array<ServiceProvider|string> $providers
     */
    #[\Override]
    public function registerProviders(array $providers): void
    {
        foreach ($providers as $provider) {
            $this->registerProvider($provider);
        }
    }

    /**
     * 启动所有已注册（非延迟）服务提供者
     */
    #[\Override]
    public function bootProviders(): void
    {
        $this->providerRegistry?->boot();
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
    /**
     * @param array<string, mixed> $parameters
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
     *
     * @param array<string, mixed> $parameters
     */
    private function build(string $concrete, array $parameters = []): mixed
    {
        if (!class_exists($concrete)) {
            throw ContainerException::notInstantiable($concrete);
        }

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

    /**
     * 检测类上的生命周期属性（仅当无既有绑定时）
     *
     * @return bool 是否已完成自动绑定
     */
    private function detectLifecycleAttribute(string $concrete): bool
    {
        if (isset($this->bindings[$concrete])) {
            return false;
        }

        if (!class_exists($concrete)) {
            return false;
        }

        // kode/attributes 2.0+ 的 Attr 门面已能正确处理 ReflectionClass 目标，
        // 直接传入反射对象即可，不会再被误读为 Reflection 类自身的属性。
        $reflector = $this->getReflector($concrete);

        if (Attr::has($reflector, Singleton::class)) {
            $this->singleton($concrete);
            return true;
        }

        if (Attr::has($reflector, Prototype::class)) {
            $this->prototype($concrete);
            return true;
        }

        if (Attr::has($reflector, ContextualAttr::class)) {
            $this->contextual($concrete);
            return true;
        }

        return false;
    }

    /**
     * 解析构造函数依赖
     *
     * @param array<int, ReflectionParameter> $parameters
     * @param array<string, mixed> $passed
     * @return array<int, mixed>
     */
    private function resolveDependencies(
        array $parameters,
        string $class,
        array $passed = []
    ): array {
        $resolved = [];

        foreach ($parameters as $parameter) {
            $name = $parameter->getName();
            $hasPassed = array_key_exists($name, $passed);

            // 可变参数：显式传入时展开，否则不追加任何实参
            if ($parameter->isVariadic()) {
                if ($hasPassed) {
                    $values = $passed[$name];

                    foreach (is_array($values) ? $values : [$values] as $value) {
                        $resolved[] = $value;
                    }
                }

                continue;
            }

            if ($hasPassed) {
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

        // 检查 #[Inject] 属性（带显式 id 时优先）。
        // kode/attributes 2.0+ 的 Attr::instance 可直接以 ReflectionParameter 为目标读取属性实例，
        // 不再需要绕过到 Attr::reader()->getParameterAttrs()。
        /** @var Inject|null $inject */
        $inject = Attr::instance($parameter, Inject::class);

        if ($inject !== null && $inject->id !== null) {
            return $this->resolve($inject->id);
        }

        $type = $parameter->getType();

        if ($type !== null) {
            try {
                $result = $this->resolveTypedParameter($type, $class);
            } catch (ServiceNotFoundException|ContainerException $e) {
                // 可空类型（含 ?T 与 T|null）解析失败时回退为 null
                if ($type->allowsNull()) {
                    return null;
                }

                throw $e;
            }

            if ($result['resolved']) {
                return $result['value'];
            }
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
     * 解析类型提示依赖，支持命名类型、联合类型与交叉类型
     *
     * @return array{resolved: bool, value: mixed}
     */
    private function resolveTypedParameter(ReflectionType $type, string $class): array
    {
        if ($type instanceof ReflectionNamedType) {
            if ($type->isBuiltin()) {
                return ['resolved' => false, 'value' => null];
            }

            $typeName = $type->getName();

            // 枚举不可实例化：仅当容器已显式实例/绑定该枚举时解析，
            // 否则交还上层走默认值/null 回退，避免尝试 new 枚举而失败。
            if (enum_exists($typeName)) {
                if (isset($this->instances[$typeName]) || isset($this->bindings[$typeName])) {
                    return ['resolved' => true, 'value' => $this->resolve($typeName)];
                }

                return ['resolved' => false, 'value' => null];
            }

            // 上下文绑定
            $contextualImpl = $this->contextual[$class][$typeName] ?? null;
            if ($contextualImpl !== null) {
                return [
                    'resolved' => true,
                    'value' => $contextualImpl instanceof Closure
                        ? $contextualImpl($this)
                        : $this->resolve($contextualImpl),
                ];
            }

            return ['resolved' => true, 'value' => $this->resolve($typeName)];
        }

        if ($type instanceof ReflectionUnionType) {
            // 联合类型成员可能是交叉类型（DNF 类型，如 (A&B)|null），需递归处理
            foreach ($type->getTypes() as $inner) {
                if ($inner instanceof ReflectionNamedType && $inner->isBuiltin()) {
                    continue;
                }

                try {
                    $result = $this->resolveTypedParameter($inner, $class);
                } catch (ServiceNotFoundException|ContainerException) {
                    // 尝试联合类型中的下一个候选
                    continue;
                }

                if ($result['resolved']) {
                    return $result;
                }
            }

            return ['resolved' => false, 'value' => null];
        }

        if ($type instanceof ReflectionIntersectionType) {
            return $this->resolveIntersectionType($type);
        }

        return ['resolved' => false, 'value' => null];
    }

    /**
     * 解析交叉类型 A&B
     *
     * 交叉类型要求实例同时满足所有成员约束，因此逐个尝试解析候选，
     * 并校验结果是否 instanceof 全部成员，只有完全满足才采用。
     *
     * @return array{resolved: bool, value: mixed}
     */
    private function resolveIntersectionType(ReflectionIntersectionType $type): array
    {
        $names = [];

        foreach ($type->getTypes() as $inner) {
            if ($inner instanceof ReflectionNamedType && !$inner->isBuiltin()) {
                $names[] = $inner->getName();
            }
        }

        if ($names === []) {
            return ['resolved' => false, 'value' => null];
        }

        foreach ($names as $candidate) {
            try {
                $instance = $this->resolve($candidate);
            } catch (ServiceNotFoundException|ContainerException) {
                continue;
            }

            if (!is_object($instance)) {
                continue;
            }

            // 校验候选实例是否满足交叉类型的全部约束
            foreach ($names as $constraint) {
                if (!$instance instanceof $constraint) {
                    continue 2;
                }
            }

            return ['resolved' => true, 'value' => $instance];
        }

        return ['resolved' => false, 'value' => null];
    }

    /**
     * 属性注入（支持 #[Inject] 与 #[Autowire]）
     */
    /**
     * @param ReflectionClass<object> $reflector
     */
    private function injectProperties(object $instance, ReflectionClass $reflector): object
    {
        foreach ($reflector->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            // kode/attributes 2.0+ 的 Attr::instance 可直接以 ReflectionProperty 为目标读取属性实例。
            /** @var Inject|null $inject */
            $inject = Attr::instance($property, Inject::class);
            /** @var Autowire|null $autowire */
            $autowire = $inject === null ? Attr::instance($property, Autowire::class) : null;

            if ($inject === null && $autowire === null) {
                continue;
            }

            $serviceId = $inject?->id;
            $required = $inject === null || $inject->required;

            if ($serviceId === null) {
                $type = $property->getType();
                if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                    $serviceId = $type->getName();
                }
            }

            if ($serviceId === null) {
                if ($required) {
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
     * 应用扩展器与解析回调（非对象值原样返回）
     *
     * 顺序：extend（可替换实例） -> resolving（观察） -> afterResolving（观察）
     */
    private function applyExtenders(string $id, mixed $instance): mixed
    {
        if (!is_object($instance)) {
            return $instance;
        }

        $id = $this->resolveAlias($id);

        foreach ($this->extenders[$id] ?? [] as $extender) {
            $extended = $extender($instance, $this);

            // 扩展器返回 null 视为「仅观察」，保留原实例，避免误吞服务
            if (is_object($extended)) {
                $instance = $extended;
            }
        }

        // 先触发指定 id 的回调，再触发全局 '*' 通配回调（观察者语义，返回值忽略）
        $this->fireResolvingCallbacks($id, $instance);

        return $instance;
    }

    /**
     * 触发解析观察者回调（resolving 在前，afterResolving 在后）。
     *
     * 同时触发指定 id 的回调与全局 '*' 通配回调，使手动注册的实例
     * 与解析出的单例在可观察性上保持一致。
     */
    private function fireResolvingCallbacks(string $id, mixed $instance): void
    {
        if (!is_object($instance)) {
            return;
        }

        $id = $this->resolveAlias($id);

        foreach ([$id, '*'] as $key) {
            foreach ($this->resolvingCallbacks[$key] ?? [] as $callback) {
                $callback($instance, $this);
            }
        }

        foreach ([$id, '*'] as $key) {
            foreach ($this->afterResolvingCallbacks[$key] ?? [] as $callback) {
                $callback($instance, $this);
            }
        }
    }

    /**
     * 调用方法并注入依赖
     *
     * @param callable|array{0: object|string, 1: string} $callback
     * @param array<string, mixed> $parameters
     */
    private function callMethod(callable|array $callback, array $parameters = []): mixed
    {
        // 支持 'Class::method' 形式的静态方法字符串
        if (is_string($callback) && str_contains($callback, '::')) {
            $callback = explode('::', $callback, 2);
        }

        if (is_array($callback)) {
            $target = $callback[0] ?? null;
            $method = $callback[1] ?? null;

            if (!is_string($method) || (!is_string($target) && !is_object($target))) {
                throw ContainerException::invalidCallable('array callable');
            }

            $declaredClass = is_string($target) ? $target : $target::class;
            $reflection = new ReflectionMethod($declaredClass, $method);

            // 静态方法：无需实例化目标类，直接以 null 为调用实例
            if ($reflection->isStatic()) {
                $dependencies = $this->resolveDependencies(
                    $reflection->getParameters(),
                    $declaredClass,
                    $parameters
                );

                return $reflection->invokeArgs(null, $dependencies);
            }

            $instance = is_string($target) ? $this->resolve($target) : $target;

            if (!is_object($instance)) {
                throw ContainerException::invalidCallable($declaredClass . '::' . $method);
            }

            $key = get_class($instance) . '::' . $method;

            // 方法绑定优先（同时兼容按声明类名注册的绑定）
            $binding = $this->methodBindings[$key]
                ?? $this->methodBindings[$declaredClass . '::' . $method]
                ?? null;

            if ($binding !== null) {
                return $binding($instance, $this);
            }

            $dependencies = $this->resolveDependencies(
                $reflection->getParameters(),
                $declaredClass,
                $parameters
            );

            return $reflection->invokeArgs($instance, $dependencies);
        }

        if ($callback instanceof Closure || is_string($callback)) {
            $reflection = new ReflectionFunction($callback);
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
     *
     * @param array<int, ReflectionParameter> $parameters
     * @param array<string, mixed> $passed
     * @return array<int, mixed>
     */
    private function resolveFunctionDependencies(array $parameters, array $passed = []): array
    {
        // 与构造函数依赖解析共用同一套规则，避免两条路径行为漂移
        return $this->resolveDependencies($parameters, self::CLOSURE_CONTEXT, $passed);
    }

    /**
     * 解析上下文隔离服务
     *
     * 当 kode/context 可用时，使用其进行协程间隔离
     *
     * @param array<string, mixed> $parameters
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
        $className = is_string($concrete) && class_exists($concrete)
            ? $concrete
            : \stdClass::class;

        $reflector = $this->getReflector($className);

        /** @phpstan-ignore-next-line method.exists (ReflectionClass::newLazyProxy 需 PHP 8.4+) */
        return $reflector->newLazyProxy(function () use ($binding) {
            return $this->buildBindingPublic($binding);
        });
    }

    /**
     * 创建传统懒加载代理 (PHP 8.1-8.3)
     *
     * 通过魔术方法转发调用，仅在首次访问时真正实例化。
     * 注意：代理为匿名类实例，不支持 instanceof 实际类型判断。
     */
    private function createLegacyLazyProxy(Binding $binding): mixed
    {
        $container = $this;

        return new class($container, $binding) implements \ArrayAccess {
            private ?object $instance = null;

            private Container $container;

            private Binding $binding;

            public function __construct(Container $container, Binding $binding)
            {
                $this->container = $container;
                $this->binding = $binding;
            }

            private function realize(): object
            {
                if ($this->instance === null) {
                    /** @var object $realized */
                    $realized = $this->container->buildBindingPublic($this->binding);
                    $this->instance = $realized;
                }

                return $this->instance;
            }

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $method, array $arguments): mixed
            {
                return $this->realize()->$method(...$arguments);
            }

            public function __get(string $name): mixed
            {
                return $this->realize()->$name;
            }

            public function __set(string $name, mixed $value): void
            {
                $this->realize()->$name = $value;
            }

            public function __isset(string $name): bool
            {
                return isset($this->realize()->$name);
            }

            public function __unset(string $name): void
            {
                unset($this->realize()->$name);
            }

            public function __invoke(mixed ...$args): mixed
            {
                $target = $this->realize();

                if (!is_callable($target)) {
                    throw ContainerException::invalidCallable($this->binding->getId());
                }

                return $target(...$args);
            }

            public function __toString(): string
            {
                $target = $this->realize();

                if (!method_exists($target, '__toString')) {
                    throw ContainerException::invalidCallable(
                        $this->binding->getId() . '::__toString'
                    );
                }

                return $target->__toString();
            }

            /**
             * @return array<string, mixed>
             */
            public function __debugInfo(): array
            {
                return [
                    'lazy' => $this->instance === null,
                    'instance' => $this->instance,
                ];
            }

            #[\Override]

            public function offsetExists(mixed $offset): bool
            {
                return isset($this->arrayTarget()[$offset]);
            }

            #[\Override]

            public function offsetGet(mixed $offset): mixed
            {
                return $this->arrayTarget()[$offset];
            }

            #[\Override]

            public function offsetSet(mixed $offset, mixed $value): void
            {
                $this->arrayTarget()[$offset] = $value;
            }

            #[\Override]

            public function offsetUnset(mixed $offset): void
            {
                unset($this->arrayTarget()[$offset]);
            }

            /**
             * @return \ArrayAccess<mixed, mixed>
             */
            private function arrayTarget(): \ArrayAccess
            {
                $target = $this->realize();

                if (!$target instanceof \ArrayAccess) {
                    throw ContainerException::invalidCallable(
                        $this->binding->getId() . '::ArrayAccess'
                    );
                }

                return $target;
            }
        };
    }

    /**
     * 公开的构建绑定方法（供懒加载代理使用）
     *
     * 会同时应用扩展器，确保懒加载实例与直接解析行为一致。
     */
    public function buildBindingPublic(Binding $binding): mixed
    {
        $instance = $this->buildBinding($binding);

        if (is_object($instance)) {
            $instance = $this->applyExtenders($binding->getId(), $instance);
        }

        return $instance;
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
     *
     * @param class-string $class
     * @return ReflectionClass<object>
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
        $this->assertNotFrozen("上下文绑定：{$when} -> {$needs}");

        $this->contextual[$when][$needs] = $give;
    }

    /**
     * 给服务打标签
     */
    #[\Override]
    public function tag(string $tag, array $ids): void
    {
        $this->assertNotFrozen('打标签');

        foreach ($ids as $id) {
            $id = $this->resolveAlias($id);

            if (!$this->bound($id)) {
                throw new ContainerException("无法为未绑定的服务打标签：{$id}");
            }

            $this->bindings[$id]->tag($tag);
        }
    }

    /**
     * 获取所有带指定标签的服务
     */
    #[\Override]
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
    #[\Override]
    public function factory(string $id): Closure
    {
        return fn(array $parameters = []) => $this->make($id, $parameters);
    }

    /**
     * 环境条件绑定（env 可注入以便测试）
     *
     * @param string|array<int, string> $environments 目标环境
     * @param Closure $callback 命中的回调
     * @param array<string, mixed>|null $env 可选的环境变量来源（覆盖 $_ENV/$_SERVER）
     */
    public function environment(string|array $environments, Closure $callback, ?array $env = null): void
    {
        $environments = (array) $environments;

        $source = $env ?? array_merge($_ENV, $_SERVER);
        $current = $source['APP_ENV'] ?? 'production';

        if (in_array($current, $environments, true)) {
            $callback($this);
        }
    }

    /**
     * 条件绑定
     *
     * 条件可以是布尔值，也可以是延迟求值的闭包（闭包接收容器自身）。
     *
     * @param bool|Closure $condition 布尔条件或返回布尔的闭包
     * @param Closure $true 条件成立时执行
     * @param Closure|null $false 条件不成立时执行
     */
    public function if(bool|Closure $condition, Closure $true, ?Closure $false = null): void
    {
        $matched = $condition instanceof Closure
            ? (bool) $condition($this)
            : $condition;

        if ($matched) {
            $true($this);
        } elseif ($false !== null) {
            $false($this);
        }
    }

    /**
     * 获取服务提供者注册表（惰性创建）
     */
    private function providers(): ServiceProviderRegistry
    {
        return $this->providerRegistry ??= new ServiceProviderRegistry($this);
    }

    /**
     * 规范化方法绑定键（支持 Class::method 与 Class@method）
     */
    private function normalizeMethodKey(string $method): string
    {
        return str_replace('@', '::', $method);
    }

    /**
     * 为未找到的服务生成相似建议
     *
     * @return string[]
     */
    private function suggestId(string $id): array
    {
        $suggestions = [];
        $threshold = max(3, (int) (strlen($id) / 3));

        foreach (array_keys($this->bindings) as $candidate) {
            if (levenshtein($id, $candidate) <= $threshold) {
                $suggestions[] = $candidate;
            }
        }

        return array_slice($suggestions, 0, 3);
    }

    /**
     * 为接口/抽象类推测具体实现类名（按命名约定）
     *
     * 候选顺序：去 Interface 后缀、去 Abstract 前缀、加 Impl、加 Default、加 Factory。
     * 命中可实例化类即返回，找不到返回 null。
     *
     * @return string|null 命中则返回类名，否则 null
     */
    private function resolveImplementationClass(string $id): ?string
    {
        $isAbstractTarget = interface_exists($id)
            || (class_exists($id) && $this->getReflector($id)->isAbstract());

        if (!$isAbstractTarget) {
            return null;
        }

        // 命名约定仅作用于「短类名」，需保留命名空间前缀（避免误改全限定名开头）
        $pos = strrpos($id, '\\');
        $namespace = $pos !== false ? (string) substr($id, 0, $pos + 1) : '';
        $short = $pos !== false ? (string) substr($id, $pos + 1) : $id;

        $base = (string) preg_replace('/Interface$/', '', $short);
        $base = (string) preg_replace('/^Abstract/', '', $base);

        $candidates = array_unique([
            $namespace . $base,
            $namespace . $base . 'Impl',
            $namespace . $base . 'Default',
            $namespace . $base . 'Factory',
        ]);

        foreach ($candidates as $candidate) {
            if ($candidate !== $id && class_exists($candidate)) {
                $reflector = $this->getReflector($candidate);
                if ($reflector->isInstantiable()) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * 强制重建单例（清除实例缓存后重新解析）
     *
     * 不会删除绑定定义，仅丢弃已解析/实例缓存，下次解析将重新构建。
     *
     * @throws \LogicException 若容器已冻结
     */
    #[\Override]
    public function refresh(string $id): mixed
    {
        $this->assertNotFrozen("重建服务：{$id}");

        $id = $this->resolveAlias($id);

        $binding = $this->bindings[$id] ?? null;

        // 实例型绑定没有可重建的具体构造器：保留原实例，
        // 仅重新挂载扩展器与解析回调后返回，避免尝试 buildBinding 而失败。
        if ($binding !== null && $binding->isInstance()) {
            $instance = $this->applyExtenders($id, $binding->getInstance());
            $binding->setInstance($instance);
            $this->instances[$id] = $instance;

            return $instance;
        }

        unset($this->instances[$id]);

        if ($binding !== null) {
            $binding->reset();
        }

        return $this->resolve($id);
    }

    /**
     * 安全获取服务，未命中时返回默认值（不抛异常）
     *
     * 与 PSR-11 的 get() 不同，本方法在服务未绑定时返回默认值而非抛错。
     */
    #[\Override]
    public function getOr(string $id, mixed $default = null): mixed
    {
        // 以「是否已显式绑定/别名/实例」判断命中，避免把「可自动解析但未注册」的类误判为命中而构建它。
        return $this->bound($id) ? $this->resolve($id) : $default;
    }

    /**
     * 冻结容器，禁止后续任何运行时增删/变更绑定
     *
     * 适用于生产环境：配置全部就绪后调用，防止运行时被意外修改。
     * 读取类方法（get/has/resolve/make/call/tagged 等）不受影响。
     */
    #[\Override]
    public function freeze(): void
    {
        $this->frozen = true;
    }

    /**
     * 容器是否已冻结
     */
    #[\Override]
    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /**
     * 设置是否自动解析接口/抽象类的具体实现
     *
     * @param bool $enabled 默认 true
     */
    #[\Override]
    public function setAutoResolveImplementations(bool $enabled): void
    {
        $this->autoResolveImplementations = $enabled;
    }

    #[\Override]
    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    #[\Override]
    public function offsetGet(mixed $offset): mixed
    {
        return $this->resolve((string) $offset);
    }

    #[\Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $id = (string) $offset;

        // 闭包作为工厂绑定；其余对象作为实例注册。
        // 注意：闭包也是对象，$value instanceof Closure 必须早于 is_object 检查。
        if ($value instanceof Closure) {
            $this->bind($id, $value);
            return;
        }

        if (is_object($value)) {
            $this->instance($id, $value);
            return;
        }

        $this->bind($id, is_string($value) ? $value : null);
    }

    #[\Override]
    public function offsetUnset(mixed $offset): void
    {
        $this->forget((string) $offset);
    }
}
