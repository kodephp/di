<?php

declare(strict_types=1);

namespace Kode\DI\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
final class Autowire
{
    public function __construct(
        public readonly bool $enabled = true
    ) {}
}
