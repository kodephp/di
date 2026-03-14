<?php

declare(strict_types=1);

namespace Kode\DI;

use Closure;

final class Definition
{
    private array $contextual = [];

    public function __construct(
        private readonly string $when,
        private ?string $needs = null
    ) {}

    public function needs(string $needs): self
    {
        $this->needs = $needs;
        return $this;
    }

    public function give(string|Closure $implementation): void
    {
        if ($this->needs === null) {
            throw new \LogicException('必须先调用 needs() 方法');
        }
        $this->contextual[$this->when][$this->needs] = $implementation;
    }

    public function getContextual(): array
    {
        return $this->contextual;
    }

    public function getWhen(): string
    {
        return $this->when;
    }

    public function getNeeds(): ?string
    {
        return $this->needs;
    }
}
