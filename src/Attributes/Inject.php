<?php

declare(strict_types=1);

namespace Kode\DI\Attributes;

use Attribute;

/**
 * 属性注入标记
 * 
 * 用于标记需要注入的类属性或构造函数参数
 * 
 * @example
 * ```php
 * class UserService
 * {
 *     #[Inject]
 *     private LoggerInterface $logger;
 *     
 *     #[Inject(id: 'cache.ttl', required: false)]
 *     private int $cacheTtl = 3600;
 * }
 * ```
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Inject
{
    /**
     * @param string|null $id 服务标识，为 null 时使用属性类型自动解析
     * @param bool $required 是否必需，false 时找不到服务不抛异常
     */
    public function __construct(
        public readonly ?string $id = null,
        public readonly bool $required = true
    ) {}
}
