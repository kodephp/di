<?php

declare(strict_types=1);

namespace Kode\DI\Exception;

use Psr\Container\NotFoundExceptionInterface;
use Exception;

/**
 * 服务未找到异常
 * 
 * 当容器无法找到指定的服务标识时抛出
 */
final class ServiceNotFoundException extends Exception implements NotFoundExceptionInterface
{
    /**
     * 创建服务未找到异常
     */
    public static function create(string $id): self
    {
        return new self("服务未找到: {$id}");
    }

    /**
     * 创建带建议的异常
     * 
     * @param string $id 服务标识
     * @param array $suggestions 可能的替代服务列表
     */
    public static function withSuggestions(string $id, array $suggestions): self
    {
        $hint = count($suggestions) > 0
            ? ' 您是否想要: ' . implode(', ', $suggestions) . '?'
            : '';
        return new self("服务未找到: {$id}{$hint}");
    }
}
