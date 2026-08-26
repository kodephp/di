# kode/di

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.3-8892BF)](https://php.net/)
[![License](https://img.shields.io/badge/License-Apache%202.0-green.svg)](LICENSE)
[![PSR-11](https://img.shields.io/badge/PSR-11-Compatible-blue)](https://www.php-fig.org/psr/psr-11/)

高性能 PHP 8.3+ 依赖注入容器，支持属性注入、生命周期管理、协程上下文隔离，兼容 PSR-11。

## 特性

- **PSR-11 兼容** - 实现标准容器接口
- **属性注入** - 基于 PHP 8.3+ Attributes 实现声明式注入
- **生命周期管理** - 单例/原型/懒加载/上下文隔离
- **上下文绑定** - `when()->needs()->give()` 按消费者定制依赖
- **服务提供者** - 支持立即注册与延迟（deferred）按需加载
- **方法绑定** - `bindMethod()` 接管指定类方法的调用
- **协程安全** - 支持 Fiber/Swoole/Swow 上下文隔离
- **高性能** - 反射缓存 + 定义缓存
- **类型健壮** - 联合类型/交叉类型/可空类型/可变参数完整支持
- **零全局状态** - 无全局变量污染
- **静态分析友好** - PHPStan `level=max` 零告警
- **框架无关** - 可在任何 PHP 8.3+ 项目中使用
- **数组语法访问** - 实现 `\ArrayAccess`，可用 `$c['id']` 读取 / 绑定 / 移除服务
- **强制重建与冻结** - `refresh()` 重建单例；`freeze()` 锁定容器防止运行时被改
- **安全获取** - `getOr()` 在未绑定服务时返回默认值而非抛异常
- **全局解析回调** - `resolving('*')` / `afterResolving('*')` 监听任意服务解析
- **接口 / 抽象类自动定位** - 按命名约定（`Foo` / `FooImpl` / `AbstractFoo` → `Foo`）自动解析实现
- **绑定内省与共享判定** - `getBinding()` 读取生命周期/标签/解析状态，`isShared()` 判定是否为共享服务
- **批量解析与按标签重建** - `resolveMany()` 一次性解析多个服务；`refreshTag()` 按标签批量重建单例
- **延迟调用包裹** - `wrap()` 预注入依赖并返回可延迟调用的闭包
- **可调用类调用** - `call('Class')` 直接调用带 `__invoke` 的类（依赖由容器注入）
- **编译 / 反射缓存** - 每个类的构造参数与可注入属性经一次反射 + 属性分析编译为不可变元数据并跨容器复用；`warmup()` 可在启动时预热，控制器每请求解析不再重复反射与读取 `#[Inject]`/`#[Autowire]` 实例

## 安装

```bash
composer require kode/di
```

## 快速开始

### 基本使用

```php
use Kode\DI\Container;

$container = new Container();

// 绑定单例
$container->singleton(LoggerInterface::class, FileLogger::class);

// 绑定原型
$container->prototype(Request::class);

// 获取实例
$logger = $container->get(LoggerInterface::class);
```

### 属性注入

```php
use Kode\DI\Attributes\Inject;
use Kode\DI\Attributes\Singleton;

#[Singleton]
class UserService
{
    #[Inject]
    private LoggerInterface $logger;

    #[Inject(id: 'cache.ttl', required: false)]
    private int $cacheTtl = 3600;
}
```

### 生命周期类型

| 类型 | 方法 | 说明 |
|------|------|------|
| 单例 | `singleton()` | 全局唯一实例 |
| 原型 | `prototype()` | 每次获取创建新实例 |
| 懒加载 | `lazy()` | 延迟实例化 |
| 上下文隔离 | `contextual()` | 协程/Fiber间隔离 |

### 上下文隔离

```php
use Kode\DI\ContextualContainer;

// 在协程环境中自动隔离实例
ContextualContainer::setContainer($container);

// 每个协程拥有独立实例
ContextualContainer::resolve(DatabaseConnection::class);
```

### 服务提供者

```php
use Kode\DI\ServiceProvider;

class DatabaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(DatabaseInterface::class, MySQLDatabase::class);
    }

    public function boot(): void
    {
        // 启动逻辑
    }
}

// 注册到容器
$container->registerProvider(DatabaseServiceProvider::class);
$container->registerProviders([CacheServiceProvider::class, QueueServiceProvider::class]);

// 统一启动（幂等，重复调用只 boot 一次）
$container->bootProviders();
```

#### 延迟（deferred）提供者

声明 `$deferred = true` 并列出 `$provides`，提供者只在其服务**首次被解析**时才加载注册，
显著降低启动开销。若容器已 `bootProviders()`，延迟加载的提供者会立即补跑 `boot()`。

```php
class ReportServiceProvider extends ServiceProvider
{
    protected bool $deferred = true;

    protected array $provides = [ReportGenerator::class];

    public function register(): void
    {
        $this->singleton(ReportGenerator::class);
    }
}

$container->registerProvider(ReportServiceProvider::class);
// 此刻尚未 register()

$container->get(ReportGenerator::class); // 触发加载 + register() + boot()
```

### 上下文绑定

```php
// 当 UserController 需要 LoggerInterface 时，使用专门的实现
$container->when(UserController::class)
    ->needs(LoggerInterface::class)
    ->give(UserLogger::class);

// 使用闭包
$container->when(OrderController::class)
    ->needs(LoggerInterface::class)
    ->give(fn($c) => new OrderLogger('order.log'));
```

### 标签

```php
// 给服务打标签
$container->singleton(CacheInterface::class, RedisCache::class)->tag('cache');
$container->singleton(SessionInterface::class, RedisSession::class)->tag('cache');

// 获取所有带标签的服务
$cacheServices = $container->tagged('cache');
```

> 注意：`tag()` 仅对**已绑定**的 id 生效，传入未绑定的 id 会抛 `ContainerException`（不再静默忽略）。

### 绑定内省与共享判定

```php
$binding = $container->getBinding(CacheInterface::class);
$binding->isSingleton();   // 生命周期
$binding->hasTag('cache'); // 标签
$binding->isResolved();    // 是否已解析

$container->isShared(CacheInterface::class); // true：单例/懒加载/上下文/实例型
$container->isShared(LoggerInterface::class); // false：原型每次新建
```

### 批量解析与按标签重建

```php
// 一次性解析多个服务，结果以服务 id 为键
$services = $container->resolveMany([DbInterface::class, CacheInterface::class]);

// 按标签批量重建单例（丢弃实例缓存、不删绑定定义；受冻结守卫约束）
$rebuilt = $container->refreshTag('cache');
```

### 编译缓存与预热

容器在首次解析某个类时，会通过一次反射 + 属性分析将其**编译为不可变元数据**（`CompiledDefinition`）并跨容器缓存：含构造函数参数列表、每个参数的 `#[Inject]` 结果、可注入属性列表及每个属性的 `#[Inject]`/`#[Autowire]` 结果。之后每次 `build()`（含单例重建、原型每次新建）与 `call()` 控制器动作都直接复用该元数据，**不再重复 `getConstructor()`/`getParameters()`/`getProperties()`，也不重复读取属性实例**——这是控制器每请求解析时的主要开销来源。

```php
// 应用引导阶段预热控制器 / 服务类，使首次请求解析不产生反射开销
$container->warmup([
    App\Controllers\HomeController::class,
    App\Controllers\UserController::class,
]);

// 静态门面同样可用
\Kode\DI\ContainerHelper::warmup([App\Controllers\HomeController::class]);
```

> 编译元数据按类名共享且不可变，跨容器安全；调用 `Container::clearCache()` 会一并清空反射、编译与方法反射缓存（实例缓存不受影响）。

### 延迟调用包裹

`wrap()` 预绑定依赖并返回可延迟调用的闭包；调用时亦可传入覆盖参数（关联数组，按参数名优先）：

```php
$fn = $container->wrap(
    fn (LoggerInterface $log, string $msg) => $log->info($msg),
    ['msg' => 'hi']
);
// 此刻尚未执行
$fn();                  // 依赖由容器解析，msg='hi'
$fn(['msg' => 'bye']); // 调用时覆盖 msg='bye'
```


### 方法绑定

接管指定「类::方法」的调用逻辑，`call()` 会优先走绑定的闭包而不做反射注入。

```php
$container->bindMethod(ReportService::class . '::generate', function (ReportService $svc, $c) {
    return $svc->generate('custom-template');
});

$container->call([ReportService::class, 'generate']); // 走绑定闭包
```

`call()` 也接受 `'Class::method'` 形式的字符串：若方法为静态方法，则无需实例化目标类即可调用。

```php
$version = $container->call(Version::class . '::current'); // 静态方法，不实例化 Version
```

此外，`call()` 还接受字符串形式的**可调用类**（带 `__invoke` 的类），会解析其实例（依赖由容器注入）后调用 `__invoke`：

```php
$result = $container->call(ReportGenerator::class); // 等价于 $container->call([$container->get(ReportGenerator::class), '__invoke'])
```

### 幂等注册

在插件化 / 多提供者场景下避免重复绑定互相覆盖，
`*If` 系列只在**尚未绑定**时才生效。

```php
$container->bindIf(LoggerInterface::class, FileLogger::class);
$container->singletonIf(CacheInterface::class, RedisCache::class);
$container->instanceIf('config', $config);

$container->bound(LoggerInterface::class); // true
```

### 解析钩子

`resolving` / `afterResolving` 是**非侵入式观察者**：回调返回值不会替换实例，
需要替换实例请使用 `extend()`。

```php
// 观察者：不改变实例
$container->resolving(Connection::class, function ($conn, $c) {
    $conn->setLogger($c->get(LoggerInterface::class));
});

// 装饰器：返回值会替换实例
$container->extend(Connection::class, fn($conn, $c) => new TracedConnection($conn));
```

### 条件注册与环境

`environment()` 支持传入自定义环境来源（便于测试），未传入时回退读取 `$_ENV` / `$_SERVER`。

```php
$container->environment('production', function ($c) {
    $c->singleton(CacheInterface::class, RedisCache::class);
});

// 测试中注入环境来源，避免依赖全局状态
$container->environment(['dev', 'testing'], $callback, ['APP_ENV' => 'testing']);

// 条件支持布尔值，也支持延迟求值的闭包（闭包接收容器自身）
$container->if($featureEnabled, $whenTrue, $whenFalse);
$container->if(fn($c) => $c->bound(CacheInterface::class), $whenTrue);
```

### 工厂

```php
$factory = $container->factory(Request::class);
$r1 = $factory();
$r2 = $factory(); // 每次调用重新解析
```

### 数组语法访问

容器实现 `\ArrayAccess`，可用原生数组语法操作服务：

```php
// 读取（等价于 $container->resolve($id)）
$logger = $container['logger'];

// 写入：对象 → 实例绑定；闭包 → 工厂绑定；字符串 → 具体类名
$container['logger']  = new FileLogger();
$container['request'] = fn() => new Request();
$container['cache']   = RedisCache::class;

// 判断与移除
isset($container['logger']);
unset($container['logger']);
```

### 强制重建与冻结

```php
// 强制重建单例：丢弃实例缓存，下次解析重新构建，但不删除绑定定义
$newInstance = $container->refresh(HeavyService::class);

// 实例型绑定（instance() 注册、无具体构造器）refresh 不会重建对象，
// 而是保留原实例并重新挂载扩展器与解析回调后返回，避免无构造器可调用而失败。

// 冻结容器：配置全部就绪后调用，禁止后续任何运行时增删 / 变更绑定。
// 读取类方法（get/has/resolve/make/call/tagged 等）不受影响，适合生产环境防误改。
// 受保护的操作包括 bind/instance/alias/extend/forget/flush/tag/refresh/
// addContextualBinding/registerProvider/bindMethod 等。
$container->freeze();
$container->isFrozen(); // true
```

### 安全获取

```php
// 服务未绑定时返回默认值，不会抛 ServiceNotFoundException
$config = $container->getOr('optional.config', []);
```

> 说明：`getOr()` 以「是否已显式绑定 / 别名 / 实例」（`bound()`）判断命中，
> 因此未注册但可自动解析的类会返回默认值，而非被悄悄构建。

### 全局解析回调

`resolving` / `afterResolving` 支持通配键 `'*'`，在**任意**服务解析时触发
（先触发指定 id 的回调，再触发全局 `'*'` 回调）：

```php
$container->resolving('*', function ($instance, $c) {
    // 对所有已解析服务统一做后置处理
});
```

> 说明：以接口 / 抽象类 id 注册的回调，在自动定位到具体实现后**同样会触发**（以原始接口 id 为粒度），无需改注册到实现类。

### 接口 / 抽象类自动定位

未显式绑定接口或抽象类时，按命名约定自动定位具体实现
（可通过 `setAutoResolveImplementations(false)` 关闭，默认开启）：

| 抽象 / 接口 | 候选实现（按优先级） |
|-------------|----------------------|
| `FooInterface` | `Foo` → `FooImpl` → `FooDefault` → `FooFactory` |
| `AbstractFoo` | `Foo` → `FooImpl` → `FooDefault` → `FooFactory` |

```php
// 未绑定 RepositoryInterface，但存在 Repository 类时自动解析
$repo = $container->get(RepositoryInterface::class);
```

## API 参考

### Container

**绑定**

| 方法 | 说明 |
|------|------|
| `bind(id, concrete, lifecycle)` | 绑定服务 |
| `singleton(id, concrete)` | 绑定单例 |
| `prototype(id, concrete)` | 绑定原型 |
| `lazy(id, concrete)` | 绑定懒加载 |
| `contextual(id, concrete)` | 绑定上下文隔离 |
| `instance(id, instance)` | 绑定已有实例（支持任意值：对象、标量、数组等）。非对象值原样存储并取回，不经过扩展器与解析回调 |
| `bindIf(id, concrete, lifecycle)` | 未绑定时才绑定 |
| `singletonIf(id, concrete)` | 未绑定时才绑定单例 |
| `instanceIf(id, instance)` | 未绑定时才绑定实例 |
| `alias(alias, id)` | 设置别名 |
| `bound(id)` | 是否已绑定（含别名 / 实例） |

**解析**

| 方法 | 说明 |
|------|------|
| `get(id)` | 获取服务（PSR-11） |
| `has(id)` | 检查服务是否可解析（PSR-11；含可自动解析的类与延迟提供者） |
| `make(id, parameters)` | 带参数创建实例 |
| `resolve(id, parameters)` | 解析服务（`make` 的底层实现） |
| `call(callback, parameters)` | 调用可调用对象并注入依赖（支持 `Class::method` 静态方法与 `Class` 可调用类） |
| `wrap(callback, parameters)` | 预注入依赖并返回可延迟调用的闭包（调用时可传覆盖参数） |
| `factory(id)` | 返回每次调用都重新解析的工厂闭包 |
| `getOr(id, default)` | 安全获取，未命中返回默认值 |
| `resolveMany(ids)` | 批量解析多个服务，结果以服务 id 为键 |
| `refresh(id)` | 强制重建单例（丢弃实例缓存，不删定义） |
| `refreshTag(tag)` | 按标签批量重建单例（受冻结守卫约束） |
| `getBinding(id)` | 获取绑定对象（内省生命周期 / 标签 / 解析状态），不存在返回 `null` |
| `isShared(id)` | 是否为共享服务（单例 / 懒加载 / 上下文 / 实例型为 true，原型为 false） |
| `\ArrayAccess` | 实现数组语法 `$c['id']` 读取 / 绑定 / 移除 |
| `resolved(id)` | 检查是否已解析 |
| `setAutoResolveImplementations(bool)` | 开启 / 关闭接口·抽象类按命名约定自动定位（默认开启） |
| `warmup(classes)` | 预热编译缓存：对一组类提前执行反射 + 属性分析，使后续解析走编译缓存、不产生反射开销 |

**上下文与扩展**

| 方法 | 说明 |
|------|------|
| `when(consumer)->needs(dep)->give(impl)` | 上下文绑定 |
| `addContextualBinding(when, needs, give)` | 直接添加上下文绑定 |
| `extend(id, callback)` | 装饰服务（返回值替换实例） |
| `resolving(id, callback)` | 解析时观察者（不改变实例） |
| `afterResolving(id, callback)` | 解析后观察者（不改变实例） |
| `rebinding(id, callback)` | 重新绑定回调 |
| `bindMethod(method, callback)` | 绑定类方法调用逻辑 |
| `tag(tag, ids)` / `tagged(tag)` | 打标签 / 按标签批量取 |

**提供者与生命周期**

| 方法 | 说明 |
|------|------|
| `registerProvider(provider)` | 注册单个服务提供者 |
| `registerProviders(providers)` | 批量注册服务提供者 |
| `bootProviders()` | 启动全部已注册提供者（幂等） |
| `environment(envs, callback, env?)` | 按环境条件注册（`env` 可注入，便于测试） |
| `if(bool\|Closure, true, false?)` | 按条件注册，条件支持闭包延迟求值 |
| `forget(id)` | 移除绑定及其实例 / 扩展器 / 上下文 |
| `flush()` | 清空容器 |
| `freeze()` / `isFrozen()` | 冻结容器 / 查询是否冻结（冻结后禁止运行时增删·变更绑定） |
| `Container::clearCache()` | 清空全局反射缓存 |

### Attributes

| 属性 | 目标 | 说明 |
|------|------|------|
| `#[Inject]` | Property, Parameter | 标记注入点 |
| `#[Autowire]` | Class, Property, Method | 启用自动装配 |
| `#[Singleton]` | Class | 标记为单例 |
| `#[Prototype]` | Class | 标记为原型 |
| `#[Contextual]` | Class | 标记为上下文隔离 |

## 健壮性说明

### 类型解析

构造函数与可调用对象的参数按以下顺序解析，覆盖 PHP 8 全部类型形态：

| 类型形态 | 行为 |
|----------|------|
| 命名类型 `Foo` | 直接解析；不可解析且不可空则抛异常 |
| 可空类型 `?Foo` | 解析失败时回退 `null`（含未绑定接口） |
| 联合类型 `A\|B` | 依次尝试各非内置成员，取第一个成功的 |
| 交叉类型 `A&B` | 逐个解析候选，仅采用 `instanceof` **全部**成员的实例 |
| DNF 类型 `(A&B)\|null` | 递归下探联合中的交叉成员，失败回退 `null` |
| 内置类型 `int/string` | 使用默认值；无默认值且不可空则抛异常 |
| 可变参数 `...$args` | 展开传入数组，未提供时为空 |
| 显式传参 | 按参数名优先命中，绕过类型解析 |

> 注意：`class_exists()` 对接口返回 `false`，容器内部统一用 `class_exists() || interface_exists()`
> 判定，避免未绑定接口在可空位置误抛异常。

### 循环依赖检测

已绑定路径与**自动解析路径**均设有递归守卫，互相依赖的未绑定类会抛出
`ContainerException` 并附带完整依赖链，而不是耗尽内存。

```php
// A 依赖 B，B 依赖 A（两者均未绑定）
$container->get(A::class); // ContainerException: 检测到循环依赖: A -> B -> A
```

### 懒加载

PHP 8.4+ 使用原生 `newLazyProxy`；低版本回退到内建代理类，
支持 `__get/__set/__isset/__unset/__call/__invoke/__toString/ArrayAccess`。
代理会缓存已实例化的目标，**底层实例只创建一次**。

## 与其他 kode 组件集成

```php
use Kode\DI\Container;
use Kode\Attributes\Attr;
use Kode\Context\Context;

// 自动使用 kode/attributes 进行属性读取
// 可选使用 kode/context 进行协程上下文隔离
```

## 兼容性

| PHP 版本 | 支持状态 |
|----------|----------|
| PHP 8.1 | ❌ 不支持（要求 8.3+） |
| PHP 8.2 | ❌ 不支持（要求 8.3+） |
| PHP 8.3 | ✅ 完全支持 |
| PHP 8.4 | ✅ 完全支持 |
| PHP 8.5 | ✅ 完全支持 |

| 框架 | 兼容性 |
|------|--------|
| Laravel | ✅ 完全兼容 |
| Symfony | ✅ 完全兼容 |
| ThinkPHP 8 | ✅ 完全兼容 |
| Webman | ✅ 完全兼容 |
| Hyperf | ✅ 完全兼容 |
| 原生 PHP | ✅ 完全兼容 |

## 测试与质量校验

```bash
composer test           # PHPUnit 全量用例
composer test:coverage  # 生成 HTML 覆盖率报告
composer check          # PHPStan level=max 静态分析
composer fix            # php-cs-fixer 按 PSR-12 格式化
```

当前状态：**63 个测试 / 98 处断言全部通过**，PHPStan `level=max` **零告警**。

## 许可证

[Apache License 2.0](LICENSE)