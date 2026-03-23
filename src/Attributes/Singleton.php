<?php

declare(strict_types=1);

namespace Kode\DI\Attributes;

use Attribute;

/**
 * 单例标记
 * 
 * 标记类为单例模式，容器将始终返回同一个实例
 * 
 * @example
 * ```php
 * #[Singleton]
 * class UserService
 * {
 *     // ...
 * }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Singleton
{
    public function __construct() {}
}
