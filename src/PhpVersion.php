<?php

declare(strict_types=1);

namespace Kode\DI;

/**
 * PHP 版本特性检测类
 * 
 * 用于检测当前 PHP 版本支持的特性，根据用户使用的 PHP 版本自动启用相应功能
 * 支持 PHP 8.1 - 8.5+ 所有版本
 */
final class PhpVersion
{
    /** @var int PHP 8.1 版本标识 */
    public const PHP_81 = 80100;

    /** @var int PHP 8.2 版本标识 */
    public const PHP_82 = 80200;

    /** @var int PHP 8.3 版本标识 */
    public const PHP_83 = 80300;

    /** @var int PHP 8.4 版本标识 */
    public const PHP_84 = 80400;

    /** @var int PHP 8.5 版本标识 */
    public const PHP_85 = 80500;

    /** @var int|null 缓存的版本号 */
    private static ?int $version = null;

    /**
     * 获取当前 PHP 版本 ID
     */
    public static function getVersion(): int
    {
        if (self::$version === null) {
            self::$version = PHP_VERSION_ID;
        }

        return self::$version;
    }

    /**
     * 是否为 PHP 8.1+
     */
    public static function is81(): bool
    {
        return self::getVersion() >= self::PHP_81;
    }

    /**
     * 是否为 PHP 8.2+
     */
    public static function is82(): bool
    {
        return self::getVersion() >= self::PHP_82;
    }

    /**
     * 是否为 PHP 8.3+
     */
    public static function is83(): bool
    {
        return self::getVersion() >= self::PHP_83;
    }

    /**
     * 是否为 PHP 8.4+
     */
    public static function is84(): bool
    {
        return self::getVersion() >= self::PHP_84;
    }

    /**
     * 是否为 PHP 8.5+
     */
    public static function is85(): bool
    {
        return self::getVersion() >= self::PHP_85;
    }

    /**
     * 是否支持只读类 (PHP 8.2+)
     */
    public static function supportsReadonlyClasses(): bool
    {
        return self::is82();
    }

    /**
     * 是否支持 Trait 中的常量 (PHP 8.2+)
     */
    public static function supportsConstantsInTraits(): bool
    {
        return self::is82();
    }

    /**
     * 是否支持析取范式类型 (DNF Types, PHP 8.2+)
     */
    public static function supportsDisjunctiveNormalFormTypes(): bool
    {
        return self::is82();
    }

    /**
     * 是否支持类型化类常量 (PHP 8.3+)
     */
    public static function supportsTypedClassConstants(): bool
    {
        return self::is83();
    }

    /**
     * 是否支持动态类常量获取 (PHP 8.3+)
     */
    public static function supportsDynamicClassConstantFetch(): bool
    {
        return self::is83();
    }

    /**
     * 是否支持非对称可见性 (PHP 8.4+)
     */
    public static function supportsAsymmetricVisibility(): bool
    {
        return self::is84();
    }

    /**
     * 是否支持属性钩子 (PHP 8.4+)
     */
    public static function supportsPropertyHooks(): bool
    {
        return self::is84();
    }

    /**
     * 是否支持无括号实例化 (PHP 8.4+)
     */
    public static function supportsNewWithoutParentheses(): bool
    {
        return self::is84();
    }

    /**
     * 是否支持 Fiber::getLocal() (PHP 8.5+)
     */
    public static function supportsFiberLocal(): bool
    {
        return self::is85();
    }

    /**
     * 是否支持原生懒加载对象 (PHP 8.4+)
     * 使用 ReflectionClass::newLazyProxy() 实现
     */
    public static function supportsLazyObjects(): bool
    {
        return self::is84();
    }

    /**
     * 获取所有特性支持状态
     */
    public static function getFeatureSet(): array
    {
        return [
            'version' => PHP_VERSION,
            'version_id' => self::getVersion(),
            'readonly_classes' => self::supportsReadonlyClasses(),
            'constants_in_traits' => self::supportsConstantsInTraits(),
            'dnf_types' => self::supportsDisjunctiveNormalFormTypes(),
            'typed_constants' => self::supportsTypedClassConstants(),
            'dynamic_constant_fetch' => self::supportsDynamicClassConstantFetch(),
            'asymmetric_visibility' => self::supportsAsymmetricVisibility(),
            'property_hooks' => self::supportsPropertyHooks(),
            'new_without_parens' => self::supportsNewWithoutParentheses(),
            'fiber_local' => self::supportsFiberLocal(),
            'lazy_objects' => self::supportsLazyObjects(),
        ];
    }
}
