<?php

declare(strict_types=1);

namespace Kode\DI\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Singleton
{
    public function __construct() {}
}
