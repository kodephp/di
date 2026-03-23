<?php

declare(strict_types=1);

namespace Kode\DI;

use Closure;

/**
 * 服务定义（上下文绑定）
 * 
 * 用于定义特定上下文条件下的服务实现
 */
final class Definition
{
    /** @var array<string, array<string, string|Closure>> 上下文绑定存储 */
    private array $contextual = [];

    /**
     * @param string $when 上下文条件
     * @param string|null $needs 需要的依赖
     */
    public function __construct(
        private readonly string $when,
        private ?string $needs = null
    ) {}

    /**
     * 指定需要的依赖
     */
    public function needs(string $needs): self
    {
        $this->needs = $needs;
        return $this;
    }

    /**
     * 指定实现
     */
    public function give(string|Closure $implementation): void
    {
        if ($this->needs === null) {
            throw new \LogicException('必须先调用 needs() 方法');
        }
        $this->contextual[$this->when][$this->needs] = $implementation;
    }

    /**
     * 获取所有上下文绑定
     */
    public function getContextual(): array
    {
        return $this->contextual;
    }

    /**
     * 获取上下文条件
     */
    public function getWhen(): string
    {
        return $this->when;
    }

    /**
     * 获取需要的依赖
     */
    public function getNeeds(): ?string
    {
        return $this->needs;
    }
}
