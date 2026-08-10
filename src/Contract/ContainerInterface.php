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
     * @param callable|array{0: object|string, 1: string} $callback
     * @param array<string, mixed> $parameters
     */
    public function call(callable|array $callback, array $parameters = []): mixed;

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
