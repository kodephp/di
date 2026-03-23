<?php

declare(strict_types=1);

namespace Kode\DI;

use Closure;
use Kode\DI\Contract\ContainerInterface;

/**
 * 服务绑定
 * 
 * 用于存储服务绑定的配置信息
 */
final class Binding
{
    /** @var string 生命周期类型 */
    private string $lifecycle = ContainerInterface::SINGLETON;

    /** @var bool 是否已解析 */
    private bool $resolved = false;

    /** @var mixed|null 实例缓存 */
    private mixed $instance = null;

    /** @var array<string, true> 标签 */
    private array $tags = [];

    /** @var array<string, array<string, string|Closure>> 上下文绑定 */
    private array $contextual = [];

    /** @var Closure|null 装饰器 */
    private ?Closure $decorator = null;

    /**
     * @param string $id 服务标识
     * @param Closure|string|null $concrete 具体实现
     */
    public function __construct(
        private readonly string $id,
        private Closure|string|null $concrete = null
    ) {
        $this->concrete ??= $id;
    }

    /**
     * 获取服务标识
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * 获取具体实现
     */
    public function getConcrete(): Closure|string|null
    {
        return $this->concrete;
    }

    /**
     * 设置具体实现
     */
    public function setConcrete(Closure|string|null $concrete): self
    {
        $this->concrete = $concrete;
        return $this;
    }

    /**
     * 获取生命周期类型
     */
    public function getLifecycle(): string
    {
        return $this->lifecycle;
    }

    /**
     * 设置生命周期类型
     */
    public function setLifecycle(string $lifecycle): self
    {
        $this->lifecycle = $lifecycle;
        return $this;
    }

    /**
     * 是否为单例
     */
    public function isSingleton(): bool
    {
        return $this->lifecycle === ContainerInterface::SINGLETON;
    }

    /**
     * 是否为原型
     */
    public function isPrototype(): bool
    {
        return $this->lifecycle === ContainerInterface::PROTOTYPE;
    }

    /**
     * 是否为懒加载
     */
    public function isLazy(): bool
    {
        return $this->lifecycle === ContainerInterface::LAZY;
    }

    /**
     * 是否为上下文隔离
     */
    public function isContextual(): bool
    {
        return $this->lifecycle === ContainerInterface::CONTEXTUAL;
    }

    /**
     * 是否已解析
     */
    public function isResolved(): bool
    {
        return $this->resolved;
    }

    /**
     * 设置已解析状态
     */
    public function setResolved(bool $resolved): self
    {
        $this->resolved = $resolved;
        return $this;
    }

    /**
     * 获取实例
     */
    public function getInstance(): mixed
    {
        return $this->instance;
    }

    /**
     * 设置实例
     */
    public function setInstance(mixed $instance): self
    {
        $this->instance = $instance;
        $this->resolved = true;
        return $this;
    }

    /**
     * 添加标签
     */
    public function tag(string|array $tags): self
    {
        foreach ((array) $tags as $tag) {
            $this->tags[$tag] = true;
        }
        return $this;
    }

    /**
     * 获取所有标签
     */
    public function getTags(): array
    {
        return array_keys($this->tags);
    }

    /**
     * 是否有指定标签
     */
    public function hasTag(string $tag): bool
    {
        return isset($this->tags[$tag]);
    }

    /**
     * 设置上下文绑定
     */
    public function when(string $when, string $needs, string|Closure $give): self
    {
        $this->contextual[$when][$needs] = $give;
        return $this;
    }

    /**
     * 获取上下文绑定
     */
    public function getContextualFor(string $when, string $needs): string|Closure|null
    {
        return $this->contextual[$when][$needs] ?? null;
    }

    /**
     * 设置装饰器
     */
    public function decorate(Closure $callback): self
    {
        $this->decorator = $callback;
        return $this;
    }

    /**
     * 获取装饰器
     */
    public function getDecorator(): ?Closure
    {
        return $this->decorator;
    }

    /**
     * 重置状态
     */
    public function reset(): void
    {
        $this->resolved = false;
        $this->instance = null;
    }
}
