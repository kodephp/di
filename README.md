# kode/di

高性能 PHP 8.1+ 依赖注入容器，支持属性注入、生命周期管理、协程上下文隔离，兼容 PSR-11。

## 特性

- **PSR-11 兼容** - 实现标准容器接口
- **属性注入** - 基于 PHP 8.1+ Attributes 实现声明式注入
- **生命周期管理** - 单例/原型/懒加载/上下文隔离
- **协程安全** - 支持 Fiber/Swoole/Swow 上下文隔离
- **高性能** - 反射缓存 + 定义缓存
- **零全局状态** - 无全局变量污染

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

## 许可证

Apache License 2.0
