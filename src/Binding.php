<?php

declare(strict_types=1);

namespace Kode\DI;

use Closure;
use Kode\DI\Contract\ContainerInterface;

final class Binding
{
    private string $lifecycle = ContainerInterface::SINGLETON;

    private bool $resolved = false;

    private mixed $instance = null;

    private array $tags = [];

    private array $contextual = [];

    private ?Closure $decorator = null;

    public function __construct(
        private readonly string $id,
        private Closure|string|null $concrete = null
    ) {
        $this->concrete ??= $id;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getConcrete(): Closure|string|null
    {
        return $this->concrete;
    }

    public function setConcrete(Closure|string|null $concrete): self
    {
        $this->concrete = $concrete;
        return $this;
    }

    public function getLifecycle(): string
    {
        return $this->lifecycle;
    }

    public function setLifecycle(string $lifecycle): self
    {
        $this->lifecycle = $lifecycle;
        return $this;
    }

    public function isSingleton(): bool
    {
        return $this->lifecycle === ContainerInterface::SINGLETON;
    }

    public function isPrototype(): bool
    {
        return $this->lifecycle === ContainerInterface::PROTOTYPE;
    }

    public function isLazy(): bool
    {
        return $this->lifecycle === ContainerInterface::LAZY;
    }

    public function isContextual(): bool
    {
        return $this->lifecycle === ContainerInterface::CONTEXTUAL;
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }

    public function setResolved(bool $resolved): self
    {
        $this->resolved = $resolved;
        return $this;
    }

    public function getInstance(): mixed
    {
        return $this->instance;
    }

    public function setInstance(mixed $instance): self
    {
        $this->instance = $instance;
        $this->resolved = true;
        return $this;
    }

    public function tag(string|array $tags): self
    {
        foreach ((array) $tags as $tag) {
            $this->tags[$tag] = true;
        }
        return $this;
    }

    public function getTags(): array
    {
        return array_keys($this->tags);
    }

    public function hasTag(string $tag): bool
    {
        return isset($this->tags[$tag]);
    }

    public function when(string $when, string $needs, string|Closure $give): self
    {
        $this->contextual[$when][$needs] = $give;
        return $this;
    }

    public function getContextualFor(string $when, string $needs): string|Closure|null
    {
        return $this->contextual[$when][$needs] ?? null;
    }

    public function decorate(Closure $callback): self
    {
        $this->decorator = $callback;
        return $this;
    }

    public function getDecorator(): ?Closure
    {
        return $this->decorator;
    }

    public function reset(): void
    {
        $this->resolved = false;
        $this->instance = null;
    }
}
