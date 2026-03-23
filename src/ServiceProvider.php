<?php

declare(strict_types=1);

namespace Kode\DI;

use Kode\DI\Contract\ContainerInterface;

/**
 * 服务提供者抽象类
 * 
 * 用于组织和管理一组相关服务的注册
 */
abstract class ServiceProvider
{
    /** @var ContainerInterface 容器实例 */
    protected ContainerInterface $container;

    /** @var array<string> 提供的内容列表 */
    protected array $provides = [];

    /** @var bool 是否延迟加载 */
    protected bool $deferred = false;

    /**
     * @param ContainerInterface $container 容器实例
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * 注册服务
     */
    abstract public function register(): void;

    /**
     * 启动服务（在所有服务注册完成后调用）
     */
    public function boot(): void
    {
    }

    /**
     * 获取提供的内容列表
     */
    public function provides(): array
    {
        return $this->provides;
    }

    /**
     * 是否延迟加载
     */
    public function isDeferred(): bool
    {
        return $this->deferred;
    }

    /**
     * 获取触发条件（用于延迟加载）
     */
    public function when(): array
    {
        return [];
    }

    /**
     * 绑定服务
     */
    protected function bind(string $id, callable|string|null $concrete = null): Binding
    {
        return $this->container->bind($id, $concrete);
    }

    /**
     * 绑定单例
     */
    protected function singleton(string $id, callable|string|null $concrete = null): Binding
    {
        return $this->container->singleton($id, $concrete);
    }

    /**
     * 绑定原型
     */
    protected function prototype(string $id, callable|string|null $concrete = null): Binding
    {
        return $this->container->prototype($id, $concrete);
    }

    /**
     * 注册实例
     */
    protected function instance(string $id, object $instance): void
    {
        $this->container->instance($id, $instance);
    }

    /**
     * 设置别名
     */
    protected function alias(string $alias, string $id): void
    {
        $this->container->alias($alias, $id);
    }

    /**
     * 扩展服务
     */
    protected function extend(string $id, callable $callback): void
    {
        $this->container->extend($id, $callback);
    }
}
