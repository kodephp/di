<?php

declare(strict_types=1);

namespace Kode\DI\Attributes;

use Attribute;

/**
 * 自动装配标记
 * 
 * 用于标记类或方法启用自动装配
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
final class Autowire
{
    /**
     * @param bool $enabled 是否启用自动装配
     */
    public function __construct(
        public readonly bool $enabled = true
    ) {}
}
