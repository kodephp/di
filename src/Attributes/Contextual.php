<?php

declare(strict_types=1);

namespace Kode\DI\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Contextual
{
    public function __construct() {}
}
