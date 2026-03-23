<?php

declare(strict_types=1);

namespace Kode\DI\Attributes;

use Attribute;

/**
 * 原型标记
 * 
 * 标记类为原型模式，每次获取都会创建新实例
 * 
 * @example
 * ```php
 * #[Prototype]
 * class RequestHandler
 * {
 *     // ...
 * }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Prototype
{
    public function __construct() {}
}
