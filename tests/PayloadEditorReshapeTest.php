<?php

declare(strict_types=1);

use MemoryPack\Editor\PayloadEditor;
use MemoryPack\MemoryPackSerializer;
use MemoryPack\Mapping\FieldDefinition;
use MemoryPack\Mapping\Type;
use MemoryPack\Editor\Tests\Fixtures\ReverseStringFormatter;

// Old shape: [a1, a2].
function reshapeOldSchema(): array
{
    return [
        FieldDefinition::of('a1', Type::INT32),
        FieldDefinition::of('a2', Type::STRING),
    ];
}

it('reshapes [a1, a2] into [a2, a3, a1, a4] reusing fields by name', function (): void {
    $payload = MemoryPackSerializer::serialize(reshapeOldSchema(), ['a1' => 7, 'a2' => 'keep']);

    // a2 and a1 reuse their current definitions (string names); a3 and a4 are new.
    $bytes = (new PayloadEditor($payload, reshapeOldSchema()))
        ->reshape('', [
            'a2',
            ['name' => 'a3', 'type' => Type::INT32, 'value' => 3],
            'a1',
            ['name' => 'a4', 'type' => Type::STRING, 'nullable' => true, 'value' => 'added'],
        ])
        ->toBytes();

    $newSchema = [
        FieldDefinition::of('a2', Type::STRING),
        FieldDefinition::of('a3', Type::INT32),
        FieldDefinition::of('a1', Type::INT32),
        FieldDefinition::of('a4', Type::STRING, nullable: true),
    ];

    expect(MemoryPackSerializer::deserialize($newSchema, $bytes))
        ->toBe(['a2' => 'keep', 'a3' => 3, 'a1' => 7, 'a4' => 'added']);
});

it('reshapes from a list of FieldDefinitions', function (): void {
    $payload = MemoryPackSerializer::serialize(reshapeOldSchema(), ['a1' => 7, 'a2' => 'keep']);

    $newShape = [
        FieldDefinition::of('a2', Type::STRING),
        FieldDefinition::of('a1', Type::INT32),
    ];

    $bytes = (new PayloadEditor($payload, reshapeOldSchema()))
        ->reshape('', $newShape)
        ->toBytes();

    expect(MemoryPackSerializer::deserialize($newShape, $bytes))
        ->toBe(['a2' => 'keep', 'a1' => 7]);
});

it('changes a field type during reshape carrying the value over', function (): void {
    $payload = MemoryPackSerializer::serialize(reshapeOldSchema(), ['a1' => 7, 'a2' => 'keep']);

    // a1: int32 -> int64, value carried by name.
    $bytes = (new PayloadEditor($payload, reshapeOldSchema()))
        ->reshape('', [
            ['name' => 'a1', 'type' => Type::INT64],
            'a2',
        ])
        ->toBytes();

    $newSchema = [
        FieldDefinition::of('a1', Type::INT64),
        FieldDefinition::of('a2', Type::STRING),
    ];

    expect(MemoryPackSerializer::deserialize($newSchema, $bytes))
        ->toBe(['a1' => 7, 'a2' => 'keep']);
});

it('reshape carries a custom formatter through an array spec', function (): void {
    $payload = MemoryPackSerializer::serialize(reshapeOldSchema(), ['a1' => 7, 'a2' => 'keep']);

    // Give a2 a reversing formatter on the way out.
    $bytes = (new PayloadEditor($payload, reshapeOldSchema()))
        ->reshape('', [
            'a1',
            ['name' => 'a2', 'type' => Type::STRING, 'formatter' => ReverseStringFormatter::class],
        ])
        ->toBytes();

    // a2 ('keep') is stored reversed ('peek') on the wire.
    expect(strpos($bytes, 'peek'))->not->toBeFalse();

    $newSchema = [
        FieldDefinition::of('a1', Type::INT32),
        FieldDefinition::of('a2', Type::STRING)->withFormatter(ReverseStringFormatter::class),
    ];

    expect(MemoryPackSerializer::deserialize($newSchema, $bytes))
        ->toBe(['a1' => 7, 'a2' => 'keep']);
});

it('seeds new field values from the values map too', function (): void {
    $payload = MemoryPackSerializer::serialize(reshapeOldSchema(), ['a1' => 7, 'a2' => 'keep']);

    $bytes = (new PayloadEditor($payload, reshapeOldSchema()))
        ->reshape('', [
            'a1',
            'a2',
            ['name' => 'a3', 'type' => Type::INT32],
        ], ['a3' => 99])
        ->toBytes();

    $newSchema = [
        FieldDefinition::of('a1', Type::INT32),
        FieldDefinition::of('a2', Type::STRING),
        FieldDefinition::of('a3', Type::INT32),
    ];

    expect(MemoryPackSerializer::deserialize($newSchema, $bytes))
        ->toBe(['a1' => 7, 'a2' => 'keep', 'a3' => 99]);
});

it('rejects reusing a field name that is not present', function (): void {
    $payload = MemoryPackSerializer::serialize(reshapeOldSchema(), ['a1' => 7, 'a2' => 'keep']);

    (new PayloadEditor($payload, reshapeOldSchema()))
        ->reshape('', ['a1', 'missing']);
})->throws(InvalidArgumentException::class);
