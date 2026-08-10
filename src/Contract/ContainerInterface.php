<?php

declare(strict_types=1);

namespace Kode\DI\Contract;

use Closure;
use Kode\DI\Binding;
use Kode\DI\Definition;
use Kode\DI\ServiceProvider;
use Psr\Container\ContainerInterface as PsrContainerInterface;

interface ContainerInterface extends PsrContainerInterface
{
    public const string SINGLETON = 'singleton';
    public const string PROTOTYPE = 'prototype';
    public const string LAZY = 'lazy';
    public const string CONTEXTUAL = 'contextual';

    public function bind(string $id, Closure|string|null $concrete = null, string $lifecycle = self::SINGLETON): Binding;

    public function singleton(string $id, Closure|string|null $concrete = null): Binding;

    public function prototype(string $id, Closure|string|null $concrete = null): Binding;

    public function lazy(string $id, Closure|string|null $concrete = null): Binding;

    public function contextual(string $id, Closure|string|null $concrete = null): Binding;

    public function bindIf(string $id, Closure|string|null $concrete = null, string $lifecycle = self::SINGLETON): Binding;

    public function singletonIf(string $id, Closure|string|null $concrete = null): Binding;

    public function instanceIf(string $id, object $instance): void;

    public function bound(string $id): bool;

    public function alias(string $alias, string $id): void;

    public function extend(string $id, Closure $callback): void;

    public function when(string $when): Definition;

    public function needs(string $needs): Definition;

    public function give(string|Closure $implementation): void;

    public function instance(string $id, object $instance): void;

    public function has(string $id): bool;

    public function resolved(string $id): bool;

    public function forget(string $id): void;

    public function flush(): void;

    /**
     * @return array<int, string>
     */
    public function getBindings(): array;

    /**
     * @return array<string, string>
     */
    public function getAliases(): array;

    /**
     * 获取指定 id 的绑定对象（用于内省生命周期/标签/解析状态）
     *
     * @return Binding|null 不存在时返回 null
     */
    public function getBinding(string $id): ?Binding;

    /**
     * 判断指定 id 是否为共享服务（单例/懒加载/上下文隔离/实例型）
     *
     * 共享服务每次解析返回同一实例；原型服务每次新建，返回 false。
     */
    public function isShared(string $id): bool;

    /**
     * 批量解析多个服务
     *
     * 结果以服务 id 为键返回。
     *
     * @param array<int, string> $ids 服务标识列表
     * @return array<string, mixed> 以服务 id 为键的解析结果
     */
    public function resolveMany(array $ids): array;

    /**
     * 按标签批量重建单例（清除缓存后重新解析，不删除绑定定义）
     *
     * @return array<string, mixed> 以服务 id 为键的重建实例
     * @throws \LogicException 若容器已冻结
     */
    public function refreshTag(string $tag): array;

    /**
     * 包裹闭包并预注入依赖，返回一个可延迟调用的闭包
     *
     * 返回的闭包被调用时，会将 $parameters 与调用时传入的实参数组合并后注入 $callback。
     *
     * @param array<string, mixed> $parameters 预置参数
     * @return Closure (...$arguments) => mixed
     */
    public function wrap(Closure $callback, array $parameters = []): Closure;

    public function registerProvider(ServiceProvider|string $provider): void;

    /**
     * @param array<int, ServiceProvider|string> $providers
     */
    public function registerProviders(array $providers): void;

    public function bootProviders(): void;

    /**
     * @param array<string, mixed> $parameters
     */
    public function resolve(string $id, array $parameters = []): mixed;

    /**
     * @param array<string, mixed> $parameters
     */
    public function make(string $id, array $parameters = []): mixed;

    /**
     * @param callable|array{0: object|string, 1: string}|string $callback 闭包/数组可调用/静态方法字符串('Class::method')/可调用类字符串('Class')
     * @param array<string, mixed> $parameters
     */
    public function call(callable|array|string $callback, array $parameters = []): mixed;

    /**
     * @param array<int, string> $ids
     */
    public function tag(string $tag, array $ids): void;

    /**
     * @return array<string, mixed>
     */
    public function tagged(string $tag): array;

    public function factory(string $id): Closure;

    /**
     * 强制重建单例（清除实例缓存后重新解析，不删除绑定定义）
     *
     * @return mixed 重建后的实例
     */
    public function refresh(string $id): mixed;

    /**
     * 安全获取服务，未命中时返回默认值（不抛异常）
     *
     * @param mixed $default 未命中时的返回值
     * @return mixed 解析结果或默认值
     */
    public function getOr(string $id, mixed $default = null): mixed;

    /**
     * 冻结容器，禁止后续运行时增删/变更绑定
     */
    public function freeze(): void;

    /**
     * 容器是否已冻结
     */
    public function isFrozen(): bool;

    /**
     * 设置是否自动解析接口/抽象类的具体实现
     */
    public function setAutoResolveImplementations(bool $enabled): void;
}
