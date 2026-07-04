<?php

declare(strict_types=1);

namespace MemoryPack\Editor\Tests\Fixtures;

use MemoryPack\Mapping\Attributes\MemoryPackField;
use MemoryPack\Mapping\Attributes\MemoryPackable;

#[MemoryPackable(valueType: true)]
final class Point
{
    #[MemoryPackField(order: 0)]
    public int $x;

    #[MemoryPackField(order: 1)]
    public int $y;
}
