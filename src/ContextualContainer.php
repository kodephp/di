<?php

declare(strict_types=1);

namespace Kode\DI;

use Closure;
use Kode\DI\Contract\ContainerInterface;
use Kode\DI\Exception\ContainerException;

final class ContextualContainer
{
    private static ?ContainerInterface $container = null;

    private static bool $contextAvailable = false;

    private static bool $checked = false;

    public static function setContainer(ContainerInterface $container): void
    {
        self::$container = $container;
    }

    public static function getContainer(): ?ContainerInterface
    {
        return self::$container;
    }

    public static function isContextAvailable(): bool
    {
        if (!self::$checked) {
            self::$contextAvailable = class_exists('Kode\Context\Context');
            self::$checked = true;
        }

        return self::$contextAvailable;
    }

    public static function get(string $id, ?string $contextKey = null): mixed
    {
        if (self::$container === null) {
            throw ContainerException::serviceNotFound($id);
        }

        $contextKey ??= self::getContextKey($id);

        if (self::isContextAvailable()) {
            return self::getFromContext($contextKey, $id);
        }

        return self::$container->get($id);
    }

    public static function set(string $id, object $instance, ?string $contextKey = null): void
    {
        if (!self::isContextAvailable()) {
            throw ContainerException::contextNotSupported($id);
        }

        $contextKey ??= self::getContextKey($id);

        $contextClass = 'Kode\Context\Context';
        $contextClass::set($contextKey, $instance);
    }

    public static function has(string $id, ?string $contextKey = null): bool
    {
        if (!self::isContextAvailable()) {
            return self::$container !== null && self::$container->has($id);
        }

        $contextKey ??= self::getContextKey($id);

        $contextClass = 'Kode\Context\Context';
        return $contextClass::has($contextKey);
    }

    public static function forget(string $id, ?string $contextKey = null): void
    {
        if (!self::isContextAvailable()) {
            return;
        }

        $contextKey ??= self::getContextKey($id);

        $contextClass = 'Kode\Context\Context';
        $contextClass::delete($contextKey);
    }

    public static function resolve(string $id, array $parameters = [], ?string $contextKey = null): mixed
    {
        if (self::$container === null) {
            throw ContainerException::serviceNotFound($id);
        }

        $contextKey ??= self::getContextKey($id);

        if (self::isContextAvailable()) {
            $contextClass = 'Kode\Context\Context';

            if ($contextClass::has($contextKey)) {
                return $contextClass::get($contextKey);
            }

            $instance = self::$container->make($id, $parameters);
            $contextClass::set($contextKey, $instance);

            return $instance;
        }

        return self::$container->make($id, $parameters);
    }

    public static function runInContext(Closure $callback, ?array $initialContext = null): mixed
    {
        if (!self::isContextAvailable()) {
            return $callback();
        }

        $contextClass = 'Kode\Context\Context';

        return $contextClass::run(function () use ($callback, $initialContext) {
            if ($initialContext !== null) {
                foreach ($initialContext as $key => $value) {
                    $contextClass::set($key, $value);
                }
            }

            return $callback();
        });
    }

    public static function fork(Closure $callback): mixed
    {
        if (!self::isContextAvailable()) {
            return $callback();
        }

        $contextClass = 'Kode\Context\Context';

        return $contextClass::fork($callback);
    }

    public static function clearContext(): void
    {
        if (!self::isContextAvailable()) {
            return;
        }

        $contextClass = 'Kode\Context\Context';
        $contextClass::clear();
    }

    private static function getContextKey(string $id): string
    {
        return 'di.contextual.' . $id;
    }

    private static function getFromContext(string $contextKey, string $id): mixed
    {
        $contextClass = 'Kode\Context\Context';

        if ($contextClass::has($contextKey)) {
            return $contextClass::get($contextKey);
        }

        if (self::$container === null) {
            throw ContainerException::serviceNotFound($id);
        }

        $instance = self::$container->make($id);
        $contextClass::set($contextKey, $instance);

        return $instance;
    }
}
