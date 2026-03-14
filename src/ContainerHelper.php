<?php

declare(strict_types=1);

namespace Kode\DI;

use Closure;
use Kode\DI\Contract\ContainerInterface;

final class ContainerHelper
{
    private static ?ContainerInterface $instance = null;

    public static function setInstance(ContainerInterface $container): void
    {
        self::$instance = $container;
        ContextualContainer::setContainer($container);
    }

    public static function getInstance(): ?ContainerInterface
    {
        return self::$instance;
    }

    public static function create(): Container
    {
        $container = new Container();
        self::setInstance($container);
        return $container;
    }

    public static function get(string $id): mixed
    {
        return self::$instance?->get($id);
    }

    public static function make(string $id, array $parameters = []): mixed
    {
        if (self::$instance === null) {
            return null;
        }

        return self::$instance->make($id, $parameters);
    }

    public static function has(string $id): bool
    {
        return self::$instance?->has($id) ?? false;
    }

    public static function bind(string $id, Closure|string|null $concrete = null): Binding
    {
        if (self::$instance === null) {
            self::create();
        }

        return self::$instance->bind($id, $concrete);
    }

    public static function singleton(string $id, Closure|string|null $concrete = null): Binding
    {
        if (self::$instance === null) {
            self::create();
        }

        return self::$instance->singleton($id, $concrete);
    }

    public static function instance(string $id, object $instance): void
    {
        self::$instance?->instance($id, $instance);
    }

    public static function call(callable $callback, array $parameters = []): mixed
    {
        if (self::$instance === null) {
            return $callback(...$parameters);
        }

        return self::$instance->call($callback, $parameters);
    }

    public static function flush(): void
    {
        self::$instance?->flush();
        self::$instance = null;
    }
}
