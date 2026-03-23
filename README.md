# kode/di

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.1-8892BF)](https://php.net/)
[![License](https://img.shields.io/badge/License-Apache%202.0-green.svg)](LICENSE)
[![PSR-11](https://img.shields.io/badge/PSR-11-Compatible-blue)](https://www.php-fig.org/psr/psr-11/)

高性能 PHP 8.1+ 依赖注入容器，支持属性注入、生命周期管理、协程上下文隔离，兼容 PSR-11。

## 特性

- **PSR-11 兼容** - 实现标准容器接口
- **属性注入** - 基于 PHP 8.1+ Attributes 实现声明式注入
- **生命周期管理** - 单例/原型/懒加载/上下文隔离
- **协程安全** - 支持 Fiber/Swoole/Swow 上下文隔离
- **高性能** - 反射缓存 + 定义缓存
- **零全局状态** - 无全局变量污染
- **框架无关** - 可在任何 PHP 8.1+ 项目中使用

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

## API 参考

### Container

| 方法 | 说明 |
|------|------|
| `bind(id, concrete, lifecycle)` | 绑定服务 |
| `singleton(id, concrete)` | 绑定单例 |
| `prototype(id, concrete)` | 绑定原型 |
| `lazy(id, concrete)` | 绑定懒加载 |
| `contextual(id, concrete)` | 绑定上下文隔离 |
| `instance(id, instance)` | 绑定实例 |
| `alias(alias, id)` | 设置别名 |
| `extend(id, callback)` | 扩展服务 |
| `get(id)` | 获取服务 |
| `has(id)` | 检查服务是否存在 |
| `make(id, parameters)` | 创建实例 |
| `call(callback, parameters)` | 调用方法 |
| `resolved(id)` | 检查是否已解析 |
| `forget(id)` | 移除绑定 |
| `flush()` | 清空容器 |

### Attributes

| 属性 | 目标 | 说明 |
|------|------|------|
| `#[Inject]` | Property, Parameter | 标记注入点 |
| `#[Autowire]` | Class, Property, Method | 启用自动装配 |
| `#[Singleton]` | Class | 标记为单例 |
| `#[Prototype]` | Class | 标记为原型 |
| `#[Contextual]` | Class | 标记为上下文隔离 |

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
| PHP 8.1 | ✅ 完全支持 |
| PHP 8.2 | ✅ 完全支持 |
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

## 测试

```bash
composer test
```

## 许可证

[Apache License 2.0](LICENSE)