<?php

declare(strict_types=1);

namespace Kode\DI\Contract;

use Closure;
use Kode\DI\Binding;
use Kode\DI\Definition;
use Psr\Container\ContainerInterface as PsrContainerInterface;

interface ContainerInterface extends PsrContainerInterface
{
    public const SINGLETON = 'singleton';
    public const PROTOTYPE = 'prototype';
    public const LAZY = 'lazy';
    public const CONTEXTUAL = 'contextual';

    public function bind(string $id, Closure|string|null $concrete = null, string $lifecycle = self::SINGLETON): Binding;

    public function singleton(string $id, Closure|string|null $concrete = null): Binding;

    public function prototype(string $id, Closure|string|null $concrete = null): Binding;

    public function lazy(string $id, Closure|string|null $concrete = null): Binding;

    public function contextual(string $id, Closure|string|null $concrete = null): Binding;

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

    public function getBindings(): array;

    public function getAliases(): array;
}
