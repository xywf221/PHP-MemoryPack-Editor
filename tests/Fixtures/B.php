<?php

declare(strict_types=1);

namespace MemoryPack\Editor\Tests\Fixtures;

use MemoryPack\Mapping\Attributes\MemoryPackField;
use MemoryPack\Mapping\Attributes\MemoryPackable;

#[MemoryPackable]
final class B
{
    #[MemoryPackField(order: 0)]
    public C $c;
}
