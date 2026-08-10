<?php

declare(strict_types=1);

namespace Kode\DI;

use Closure;

/**
 * 服务定义（上下文绑定构造器）
 *
 * 由 Container::when() 创建并持有容器引用，
 * needs()/give() 链式调用最终通过容器写入上下文绑定。
 */
final class Definition
{
    /**
     * @param string $when 上下文条件（消费方类）
     * @param Container|null $container 关联的容器实例
     * @param string|null $needs 需要的依赖类型
     */
    public function __construct(
        private readonly string $when,
        private ?Container $container = null,
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
     * 指定实现，写入容器的上下文绑定
     */
    public function give(string|Closure $implementation): void
    {
        if ($this->needs === null) {
            throw new \LogicException('必须先调用 needs() 方法');
        }

        if ($this->container === null) {
            throw new \LogicException('Definition 未关联容器，请通过 Container::when() 创建');
        }

        $this->container->addContextualBinding($this->when, $this->needs, $implementation);
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
