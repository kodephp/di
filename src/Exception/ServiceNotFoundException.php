<?php

declare(strict_types=1);

namespace Kode\DI\Exception;

use Psr\Container\NotFoundExceptionInterface;
use Exception;

final class ServiceNotFoundException extends Exception implements NotFoundExceptionInterface
{
    public static function create(string $id): self
    {
        return new self("服务未找到: {$id}");
    }

    public static function withSuggestions(string $id, array $suggestions): self
    {
        $hint = count($suggestions) > 0
            ? ' 您是否想要: ' . implode(', ', $suggestions) . '?'
            : '';
        return new self("服务未找到: {$id}{$hint}");
    }
}
