<?php

declare(strict_types=1);

namespace Kode\DI\Attributes;

use Attribute;

/**
 * 上下文隔离标记
 * 
 * 标记类为上下文隔离模式，在协程/Fiber环境下每个协程拥有独立实例
 * 
 * @example
 * ```php
 * #[Contextual]
 * class DatabaseConnection
 * {
 *     // 每个协程将有独立的数据库连接实例
 * }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Contextual
{
    public function __construct() {}
}
