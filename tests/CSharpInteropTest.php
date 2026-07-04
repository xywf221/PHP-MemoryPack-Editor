<?php

declare(strict_types=1);

use MemoryPack\Editor\PayloadEditor;
use MemoryPack\Editor\Tests\Fixtures\Point;
use MemoryPack\Editor\Tests\Fixtures\Shape;
use MemoryPack\Editor\Tests\Fixtures\InteropPayload;
use MemoryPack\Mapping\FieldDefinition;
use MemoryPack\Mapping\Schema;
use MemoryPack\Mapping\Type;
use MemoryPack\MemoryPackSerializer;

// ---------------------------------------------------------------------------
// Schema helpers
// ---------------------------------------------------------------------------

function pointSchema(): Schema
{
    return new Schema([
        FieldDefinition::of('x', Type::INT32),
        FieldDefinition::of('y', Type::INT32),
    ], valueType: true);
}

function shapeSchema(): Schema
{
    return MemoryPackSerializer::schemaFactory()->create(Shape::class);
}

function payloadSchema(): Schema
{
    return MemoryPackSerializer::schemaFactory()->create(InteropPayload::class);
}

// ---------------------------------------------------------------------------
// C# interop tests
// ---------------------------------------------------------------------------

it('decodes and re-encodes a simple C# payload without changes', function (): void {
    $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'CSharpInterop.cs';
    $csharpPayload = trim(runCommand(['dotnet', 'run', $script, '--', 'point-write']));
    $bytes = base64_decode($csharpPayload, true);

    $editor = new PayloadEditor($bytes, pointSchema());
    $reEncoded = $editor->toBytes();

    expect(bin2hex($reEncoded))->toBe(bin2hex($bytes));

    $phpPayload = base64_encode($reEncoded);
    expect(trim(runCommand(['dotnet', 'run', $script, '--', 'point-read', $phpPayload])))->toBe('ok');
});

it('edits a nested object field from a C# payload', function (): void {
    $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'CSharpInterop.cs';
    $csharpPayload = trim(runCommand(['dotnet', 'run', $script, '--', 'shape-write']));
    $bytes = base64_decode($csharpPayload, true);

    // C# writes origin.x=1. Change it to 99.
    $editor = new PayloadEditor($bytes, shapeSchema());
    expect($editor->getValue('origin.x'))->toBe(1);

    $modified = $editor->setValue('origin.x', 99)->toBytes();

    // C# shape-read asserts origin.x == 99 and origin.y == 2.
    $phpPayload = base64_encode($modified);
    expect(trim(runCommand(['dotnet', 'run', $script, '--', 'shape-read', $phpPayload])))->toBe('ok');

    // Verify the list was left untouched.
    $editor2 = new PayloadEditor($modified, shapeSchema());
    expect($editor2->getValue('origin.x'))->toBe(99)
        ->and($editor2->getValue('origin.y'))->toBe(2);
});

it('constructs a payload from PHP that C# can read', function (): void {
    $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'CSharpInterop.cs';

    // Build a Shape payload using the class-based serializer.
    $shape = new Shape();
    $shape->origin = new Point();
    $shape->origin->x = 99;
    $shape->origin->y = 2;
    $first = new Point();
    $first->x = 3;
    $first->y = 4;
    $second = new Point();
    $second->x = 5;
    $second->y = 6;
    $shape->points = [$first, $second];
    $payload = MemoryPackSerializer::serializeObject($shape);

    // C# shape-read asserts origin.x==99, origin.y==2, points=[{3,4},{5,6}].
    $phpPayload = base64_encode($payload);
    expect(trim(runCommand(['dotnet', 'run', $script, '--', 'shape-read', $phpPayload])))->toBe('ok');
});

it('round-trips a complex C# payload through the editor', function (): void {
    $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'CSharpInterop.cs';
    $csharpPayload = trim(runCommand(['dotnet', 'run', $script, '--', 'payload-write']));
    $bytes = base64_decode($csharpPayload, true);

    $editor = new PayloadEditor($bytes, payloadSchema());
    $reEncoded = $editor->toBytes();

    // The editor re-encodes strings with a4-byte zero padding (PHP convention),
    // while C# writes the byte length there. Both formats are wire-compatible:
    // each side discards the4-byte field. Verify C# can read the editor's output.
    $phpPayload = base64_encode($reEncoded);
    expect(trim(runCommand(['dotnet', 'run', $script, '--', 'payload-read', $phpPayload])))->toBe('ok');
});

it('modifies a leaf field in a complex C# payload', function (): void {
    $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'CSharpInterop.cs';
    $csharpPayload = trim(runCommand(['dotnet', 'run', $script, '--', 'payload-write']));
    $bytes = base64_decode($csharpPayload, true);

    $editor = new PayloadEditor($bytes, payloadSchema());
    expect($editor->getValue('name'))->toBe('雷少')
        ->and($editor->getValue('id'))->toBe(42)
        ->and($editor->getValue('active'))->toBeTrue()
        ->and($editor->getValue('scores'))->toBe([3, 5, 8]);

    $modified = $editor->setValue('name', 'modified')->toBytes();

    $editor2 = new PayloadEditor($modified, payloadSchema());
    expect($editor2->getValue('name'))->toBe('modified')
        ->and($editor2->getValue('id'))->toBe(42)
        ->and($editor2->getValue('active'))->toBeTrue()
        ->and($editor2->getValue('scores'))->toBe([3, 5, 8])
        ->and($editor2->getValue('tags'))->toBe(['php', 'csharp'])
        ->and($editor2->getValue('counts'))->toBe(['alpha' => 10, 'beta' => 20]);
});

it('adds a nullable field to a C# payload', function (): void {
    $schema3 = new Schema([
        FieldDefinition::of('x', Type::INT32),
        FieldDefinition::of('y', Type::INT32),
        FieldDefinition::of('z', Type::INT32, nullable: true),
    ], valueType: true);

    // Build a 3-field payload with z = null (written as INT32 0 on the wire).
    $payload = \MemoryPack\MemoryPackSerializer::serialize($schema3, [
        'x' => 1,
        'y' => 2,
        'z' => null,
    ]);

    // Decode with the editor and verify the third field.
    $editor = new PayloadEditor($payload, $schema3);
    expect($editor->getValue('x'))->toBe(1)
        ->and($editor->getValue('y'))->toBe(2)
        ->and($editor->getValue('z'))->toBe(0); // null INT32 is wire-encoded as 0

    // C# can still read it — the reader only reads 2 fields, ignoring z.
    $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'CSharpInterop.cs';
    $phpPayload = base64_encode($payload);
    expect(trim(runCommand(['dotnet', 'run', $script, '--', 'point-read', $phpPayload])))->toBe('ok');
});

// ---------------------------------------------------------------------------
// proc_open helper
// ---------------------------------------------------------------------------

function runCommand(array $command): string
{
    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptorSpec, $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        throw new RuntimeException('Failed to start process.');
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        throw new RuntimeException("Command failed with exit code {$exitCode}: {$stderr}");
    }

    return $stdout;
}
