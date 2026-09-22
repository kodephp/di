<?php

declare(strict_types=1);

namespace Kode\DI\Attributes;

use Attribute;

/**
 * 自动装配标记
 *
 * 容器只消费**属性（Property）**位置的声明：
 *  - `#[Autowire]` 标在属性上 = 该属性按类型自动注入；
 *  - `#[Autowire(false)]` 标在属性上 = 显式关掉该属性的自动装配（与 #[Inject] 并存时以 Inject 为准）。
 *
 * 类/方法位置仅为历史声明保留，容器不读取、也不会因此对该类的属性做整体装配——
 * 想让某个属性被注入，请把注解写在该属性上。
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
