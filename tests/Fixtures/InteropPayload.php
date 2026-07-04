<?php

declare(strict_types=1);

namespace MemoryPack\Editor\Tests\Fixtures;

use MemoryPack\Mapping\Attributes\Int32Field;
use MemoryPack\Mapping\Attributes\MemoryPackField;
use MemoryPack\Mapping\Attributes\MemoryPackable;
use MemoryPack\Mapping\Attributes\StringField;

#[MemoryPackable]
final class InteropPayload
{
    #[MemoryPackField(order: 0)]
    public int $id;

    #[MemoryPackField(order: 1)]
    public string $name;

    #[MemoryPackField(order: 2, type: 'bool')]
    public bool $active;

    #[MemoryPackField(order: 3, type: 'list', element: new Int32Field())]
    public array $scores;

    #[MemoryPackField(order: 4, type: 'list', element: new StringField())]
    public array $tags;

    #[MemoryPackField(order: 5, type: 'dict', key: new StringField(), element: new Int32Field())]
    public array $counts;

    #[MemoryPackField(order: 6)]
    public Point $origin;
}
