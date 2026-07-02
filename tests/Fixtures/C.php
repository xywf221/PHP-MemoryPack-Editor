<?php

declare(strict_types=1);

namespace MemoryPack\Editor\Tests\Fixtures;

use MemoryPack\Mapping\Attributes\MemoryPackField;
use MemoryPack\Mapping\Attributes\MemoryPackable;
use MemoryPack\Mapping\Type;

#[MemoryPackable]
final class C
{
    #[MemoryPackField(order: 0, type: Type::STRING)]
    public string $name;
}
