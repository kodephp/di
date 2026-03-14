<?php

declare(strict_types=1);

namespace Kode\DI;

final class PhpVersion
{
    public const PHP_81 = 80100;
    public const PHP_82 = 80200;
    public const PHP_83 = 80300;
    public const PHP_84 = 80400;
    public const PHP_85 = 80500;

    private static ?int $version = null;

    public static function getVersion(): int
    {
        if (self::$version === null) {
            self::$version = PHP_VERSION_ID;
        }

        return self::$version;
    }

    public static function is81(): bool
    {
        return self::getVersion() >= self::PHP_81;
    }

    public static function is82(): bool
    {
        return self::getVersion() >= self::PHP_82;
    }

    public static function is83(): bool
    {
        return self::getVersion() >= self::PHP_83;
    }

    public static function is84(): bool
    {
        return self::getVersion() >= self::PHP_84;
    }

    public static function is85(): bool
    {
        return self::getVersion() >= self::PHP_85;
    }

    public static function supportsReadonlyClasses(): bool
    {
        return self::is82();
    }

    public static function supportsConstantsInTraits(): bool
    {
        return self::is82();
    }

    public static function supportsDisjunctiveNormalFormTypes(): bool
    {
        return self::is82();
    }

    public static function supportsTypedClassConstants(): bool
    {
        return self::is83();
    }

    public static function supportsDynamicClassConstantFetch(): bool
    {
        return self::is83();
    }

    public static function supportsAsymmetricVisibility(): bool
    {
        return self::is84();
    }

    public static function supportsPropertyHooks(): bool
    {
        return self::is84();
    }

    public static function supportsNewWithoutParentheses(): bool
    {
        return self::is84();
    }

    public static function supportsFiberLocal(): bool
    {
        return self::is85();
    }

    public static function supportsLazyObjects(): bool
    {
        return self::is84();
    }

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
