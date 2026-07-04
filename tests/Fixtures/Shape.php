<?php

declare(strict_types=1);

namespace MemoryPack\Editor\Tests\Fixtures;

use MemoryPack\Mapping\Attributes\MemoryPackField;
use MemoryPack\Mapping\Attributes\MemoryPackable;
use MemoryPack\Mapping\Attributes\ObjectField;

#[MemoryPackable]
final class Shape
{
    #[MemoryPackField(order: 0)]
    public Point $origin;

    #[MemoryPackField(order: 1, type: 'list', element: new ObjectField(Point::class))]
    public array $points;
}
