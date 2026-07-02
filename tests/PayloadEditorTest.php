<?php

declare(strict_types=1);

use MemoryPack\Editor\PayloadEditor;
use MemoryPack\MemoryPackSerializer;
use MemoryPack\Mapping\FieldDefinition;
use MemoryPack\Mapping\Type;
use MemoryPack\Editor\Tests\Fixtures\A;
use MemoryPack\Editor\Tests\Fixtures\B;
use MemoryPack\Editor\Tests\Fixtures\C;
use MemoryPack\Editor\Tests\Fixtures\ReverseStringFormatter;

// Old wire shape, written with a hand-supplied schema (no classes for the leaves).
function oldStructSchema(): array
{
    return [
        FieldDefinition::of('id', Type::INT32),
        FieldDefinition::of('score', Type::INT32),
        FieldDefinition::of('name', Type::STRING),
    ];
}

it('reads values from a payload using the supplied schema', function (): void {
    $payload = MemoryPackSerializer::serialize(oldStructSchema(), [
        'id' => 7,
        'score' => 42,
        'name' => 'leaf',
    ]);

    $editor = new PayloadEditor($payload, oldStructSchema());

    expect($editor->getValue('id'))->toBe(7)
        ->and($editor->getValue('name'))->toBe('leaf')
        ->and($editor->has('score'))->toBeTrue()
        ->and($editor->has('missing'))->toBeFalse()
        ->and($editor->toArray())->toBe(['id' => 7, 'score' => 42, 'name' => 'leaf']);
});

it('exports the payload tree as a PHP object and JSON', function (): void {
    $payload = MemoryPackSerializer::serialize(oldStructSchema(), [
        'id' => 7,
        'score' => 42,
        'name' => 'leaf',
    ]);

    $editor = new PayloadEditor($payload, oldStructSchema());
    $object = $editor->toObject();

    expect($object)->toBeInstanceOf(stdClass::class)
        ->and($object->id)->toBe(7)
        ->and($object->score)->toBe(42)
        ->and($object->name)->toBe('leaf')
        ->and($editor->toJson())->toBe('{"id":7,"score":42,"name":"leaf"}');
});

it('changes a value and re-encodes', function (): void {
    $payload = MemoryPackSerializer::serialize(oldStructSchema(), ['id' => 7, 'score' => 42, 'name' => 'leaf']);

    $bytes = (new PayloadEditor($payload, oldStructSchema()))
        ->setValue('score', 100)
        ->replace('id', fn (int $old): int => $old + 1)
        ->toBytes();

    expect(MemoryPackSerializer::deserialize(oldStructSchema(), $bytes))
        ->toBe(['id' => 8, 'score' => 100, 'name' => 'leaf']);
});

it('changes a field type by swapping its definition', function (): void {
    $payload = MemoryPackSerializer::serialize(oldStructSchema(), ['id' => 7, 'score' => 42, 'name' => 'leaf']);

    // score: int32 -> int64, keeping its wire position.
    $bytes = (new PayloadEditor($payload, oldStructSchema()))
        ->removeProperty('score')
        ->addProperty('', FieldDefinition::of('score', Type::INT64), 0x1_0000_0000, order: 1)
        ->toBytes();

    $newSchema = [
        FieldDefinition::of('id', Type::INT32),
        FieldDefinition::of('score', Type::INT64),
        FieldDefinition::of('name', Type::STRING),
    ];

    expect(MemoryPackSerializer::deserialize($newSchema, $bytes))
        ->toBe(['id' => 7, 'score' => 0x1_0000_0000, 'name' => 'leaf']);
});

it('removes a field', function (): void {
    $payload = MemoryPackSerializer::serialize(oldStructSchema(), ['id' => 7, 'score' => 42, 'name' => 'leaf']);

    $bytes = (new PayloadEditor($payload, oldStructSchema()))
        ->removeProperty('score')
        ->toBytes();

    $newSchema = [
        FieldDefinition::of('id', Type::INT32),
        FieldDefinition::of('name', Type::STRING),
    ];

    expect(MemoryPackSerializer::deserialize($newSchema, $bytes))
        ->toBe(['id' => 7, 'name' => 'leaf']);
});

it('inserts a field at the front in wire order', function (): void {
    $payload = MemoryPackSerializer::serialize(oldStructSchema(), ['id' => 7, 'score' => 42, 'name' => 'leaf']);

    $bytes = (new PayloadEditor($payload, oldStructSchema()))
        ->addProperty('', FieldDefinition::of('d', Type::INT32), 99, order: 0)
        ->toBytes();

    // Header is now 4 members and 'd' is written first.
    expect(bin2hex(substr($bytes, 0, 5)))->toBe('04' . '63000000');

    $newSchema = [
        FieldDefinition::of('d', Type::INT32),
        FieldDefinition::of('id', Type::INT32),
        FieldDefinition::of('score', Type::INT32),
        FieldDefinition::of('name', Type::STRING),
    ];

    expect(MemoryPackSerializer::deserialize($newSchema, $bytes))
        ->toBe(['d' => 99, 'id' => 7, 'score' => 42, 'name' => 'leaf']);
});

it('edits deep nested object fields through a dotted path', function (): void {
    $a = new A();
    $a->b = new B();
    $a->b->c = new C();
    $a->b->c->name = 'old';

    $payload = MemoryPackSerializer::serializeObject($a);
    $schema = MemoryPackSerializer::schemaFactory()->create(A::class);

    $editor = new PayloadEditor($payload, $schema);
    expect($editor->getValue('b.c.name'))->toBe('old');

    $object = $editor->toObject();
    expect($object->b)->toBeInstanceOf(stdClass::class)
        ->and($object->b->c)->toBeInstanceOf(stdClass::class)
        ->and($object->b->c->name)->toBe('old');

    $bytes = $editor->setValue('b.c.name', 'new')->toBytes();

    $result = MemoryPackSerializer::deserializeObject(A::class, $bytes);
    expect($result->b->c->name)->toBe('new');
});

it('reorders an existing field', function (): void {
    $payload = MemoryPackSerializer::serialize(oldStructSchema(), ['id' => 7, 'score' => 42, 'name' => 'leaf']);

    $bytes = (new PayloadEditor($payload, oldStructSchema()))
        ->setOrder('name', 0)
        ->toBytes();

    $newSchema = [
        FieldDefinition::of('name', Type::STRING),
        FieldDefinition::of('id', Type::INT32),
        FieldDefinition::of('score', Type::INT32),
    ];

    expect(MemoryPackSerializer::deserialize($newSchema, $bytes))
        ->toBe(['name' => 'leaf', 'id' => 7, 'score' => 42]);
});

it('decodes and re-encodes through a field custom formatter', function (): void {
    // 'name' carries a custom formatter that reverses the string on the wire.
    $schema = [
        FieldDefinition::of('id', Type::INT32),
        FieldDefinition::of('name', Type::STRING)->withFormatter(ReverseStringFormatter::class),
    ];

    $payload = MemoryPackSerializer::serialize($schema, ['id' => 7, 'name' => 'leaf']);
    // The wire bytes are reversed ('fael'), not 'leaf'.
    expect(strpos($payload, 'fael'))->not->toBeFalse();

    $editor = new PayloadEditor($payload, $schema);

    // Decode ran the formatter, so getValue sees the plain string.
    expect($editor->getValue('name'))->toBe('leaf');

    $bytes = $editor->setValue('name', 'gold')->toBytes();

    // Encode ran the formatter again: the new value is stored reversed on the wire.
    expect(strpos($bytes, 'dlog'))->not->toBeFalse()
        ->and(MemoryPackSerializer::deserialize($schema, $bytes))
        ->toBe(['id' => 7, 'name' => 'gold']);
});
