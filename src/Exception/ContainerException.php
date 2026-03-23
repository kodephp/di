<?php

declare(strict_types=1);

namespace Kode\DI\Exception;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

/**
 * 容器异常
 * 
 * 容器操作过程中的各种异常情况
 */
final class ContainerException extends RuntimeException implements ContainerExceptionInterface
{
    /**
     * 创建循环依赖异常
     */
    public static function circularReference(string $id, array $stack): self
    {
        $path = implode(' -> ', [...$stack, $id]);
        return new self("检测到循环依赖: {$path}");
    }

    /**
     * 创建无法实例化异常
     */
    public static function notInstantiable(string $id): self
    {
        return new self("无法实例化: {$id}");
    }

    /**
     * 创建未找到绑定异常
     */
    public static function bindingNotFound(string $id): self
    {
        return new self("未找到绑定: {$id}");
    }

    /**
     * 创建无法解析参数异常
     */
    public static function unresolvedParameter(string $parameter, string $class): self
    {
        return new self("无法解析参数 \${$parameter} 在 {$class} 中");
    }

    /**
     * 创建无效绑定类型异常
     */
    public static function invalidBinding(string $id, mixed $value): self
    {
        $type = gettype($value);
        return new self("无效的绑定类型 [{$type}] 用于: {$id}");
    }

    /**
     * 创建服务未找到异常
     */
    public static function serviceNotFound(string $id): self
    {
        return new self("服务未找到: {$id}");
    }

    /**
     * 创建上下文不支持异常
     */
    public static function contextNotSupported(string $id): self
    {
        return new self("上下文隔离需要 kode/context 包支持: {$id}");
    }
}
