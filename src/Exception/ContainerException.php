<?php

declare(strict_types=1);

namespace Kode\DI\Exception;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

final class ContainerException extends RuntimeException implements ContainerExceptionInterface
{
    public static function circularReference(string $id, array $stack): self
    {
        $path = implode(' -> ', [...$stack, $id]);
        return new self("检测到循环依赖: {$path}");
    }

    public static function notInstantiable(string $id): self
    {
        return new self("无法实例化: {$id}");
    }

    public static function bindingNotFound(string $id): self
    {
        return new self("未找到绑定: {$id}");
    }

    public static function unresolvedParameter(string $parameter, string $class): self
    {
        return new self("无法解析参数 \${$parameter} 在 {$class} 中");
    }

    public static function invalidBinding(string $id, mixed $value): self
    {
        $type = gettype($value);
        return new self("无效的绑定类型 [{$type}] 用于: {$id}");
    }

    public static function serviceNotFound(string $id): self
    {
        return new self("服务未找到: {$id}");
    }

    public static function contextNotSupported(string $id): self
    {
        return new self("上下文隔离需要 kode/context 包支持: {$id}");
    }
}
