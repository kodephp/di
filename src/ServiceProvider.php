<?php

declare(strict_types=1);

namespace Kode\DI;

use Kode\DI\Contract\ContainerInterface;

abstract class ServiceProvider
{
    protected ContainerInterface $container;

    protected array $provides = [];

    protected bool $deferred = false;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    abstract public function register(): void;

    public function boot(): void
    {
    }

    public function provides(): array
    {
        return $this->provides;
    }

    public function isDeferred(): bool
    {
        return $this->deferred;
    }

    public function when(): array
    {
        return [];
    }

    protected function bind(string $id, callable|string|null $concrete = null): Binding
    {
        return $this->container->bind($id, $concrete);
    }

    protected function singleton(string $id, callable|string|null $concrete = null): Binding
    {
        return $this->container->singleton($id, $concrete);
    }

    protected function prototype(string $id, callable|string|null $concrete = null): Binding
    {
        return $this->container->prototype($id, $concrete);
    }

    protected function instance(string $id, object $instance): void
    {
        $this->container->instance($id, $instance);
    }

    protected function alias(string $alias, string $id): void
    {
        $this->container->alias($alias, $id);
    }

    protected function extend(string $id, callable $callback): void
    {
        $this->container->extend($id, $callback);
    }
}
