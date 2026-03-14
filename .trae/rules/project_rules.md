# Kode/DI 项目规则

## 命名规范

| 简称 | 全称 | 说明 |
|------|------|------|
| `DI` | Dependency Injection | 依赖注入 |
| `IoC` | Inversion of Control | 控制反转 |
| `SRV` | Service | 服务实例 |
| `BIND` | Binding | 绑定定义 |
| `CTX` | Context | 上下文隔离 |
| `DEF` | Definition | 服务定义 |
| `SP` | ServiceProvider | 服务提供者 |

## 目录结构

```
src/
├── Container.php          # 核心容器
├── Binding.php            # 绑定定义
├── Definition.php         # 服务定义
├── ContextualContainer.php # 上下文隔离容器
├── ServiceProvider.php    # 服务提供者抽象
├── Attributes/
│   ├── Inject.php         # 注入属性
│   └── Autowire.php       # 自动装配属性
├── Exception/
│   └── ContainerException.php
└── Contract/
    └── ContainerInterface.php
```

## 核心命令

```bash
# 运行测试
composer test

# 代码风格检查
composer check

# 代码风格修复
composer fix

# 静态分析
composer analyse
```

## 生命周期类型

| 类型 | 常量 | 说明 |
|------|------|------|
| 单例 | `SINGLETON` | 全局唯一实例 |
| 原型 | `PROTOTYPE` | 每次获取创建新实例 |
| 懒加载 | `LAZY` | 延迟实例化 |
| 上下文隔离 | `CONTEXTUAL` | 协程/Fiber间隔离 |

## 设计原则

1. **零全局状态**: 不使用全局变量，通过注入传递
2. **协程安全**: 支持 Fiber/Swoole/Swow 上下文隔离
3. **PSR-11 兼容**: 实现标准容器接口
4. **属性注入**: 结合 kode/attributes 实现声明式注入
5. **高性能**: 反射缓存 + 定义缓存
