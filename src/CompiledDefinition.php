<?php

declare(strict_types=1);

namespace Kode\DI;

use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use Kode\DI\Attributes\Inject;
use Kode\DI\Attributes\Autowire;

/**
 * 编译后的类构建元数据
 *
 * 在首次解析类时通过一次反射 + 属性分析生成，之后复用：
 * - 构造函数（可能为 null）与构造参数列表
 * - 每个构造参数的 {@see Inject} 分析结果（无则 null）
 * - 可注入属性列表（已排除静态属性）与每个属性的 {@see Inject}/{@see Autowire} 分析结果
 *
 * 反射与目标类绑定，跨容器共享且不可变，避免每次 build() 重复反射与重复读取属性实例。
 */
final class CompiledDefinition
{
    /**
     * @param array<int, ReflectionParameter> $params
     * @param array<int, ReflectionProperty> $properties
     * @param array<string, Inject|null> $paramInject 参数名 => #[Inject]（无则 null）
     * @param array<string, array{inject: Inject|null, autowire: Autowire|null}> $propMeta 属性名 => 属性分析结果
     */
    public function __construct(
        public readonly ?ReflectionMethod $constructor,
        public readonly array $params,
        public readonly array $properties,
        public readonly array $paramInject,
        public readonly array $propMeta,
    ) {}
}
