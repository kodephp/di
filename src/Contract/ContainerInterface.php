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
}
