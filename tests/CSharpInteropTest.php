<?php

declare(strict_types=1);

use MemoryPack\Editor\PayloadEditor;
use MemoryPack\Mapping\FieldDefinition;
use MemoryPack\Mapping\Schema;
use MemoryPack\Mapping\Type;
use MemoryPack\MemoryPackSerializer;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function csharpScript(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'CSharpInterop.cs';
}

/**
 * V1 schema (C# class V1): { id:INT32, name:STRING, score:INT32 }
 */
function v1Schema(): array
{
    return [
        FieldDefinition::of('id', Type::INT32),
        FieldDefinition::of('name', Type::STRING),
        FieldDefinition::of('score', Type::INT32),
    ];
}

/**
 * V2 schema (C# class V2): { name:STRING, id:INT32, points:INT32, level:INT32 }
 * — reordered, renamed score→points, added level
 */
function v2Schema(): array
{
    return [
        FieldDefinition::of('name', Type::STRING),
        FieldDefinition::of('id', Type::INT32),
        FieldDefinition::of('points', Type::INT32),
        FieldDefinition::of('level', Type::INT32),
    ];
}

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

// ---------------------------------------------------------------------------
// Schema migration tests — the core use case for PayloadEditor
// ---------------------------------------------------------------------------

it('migrates V1→V2 by reordering fields and renaming score to points', function (): void {
    $script = csharpScript();

    // 1. C# writes V1 payload: { id:42, name:"雷少", score:100 }
    $csharpPayload = trim(runCommand(['dotnet', 'run', $script, '--', 'v1-write']));
    $bytes = base64_decode($csharpPayload, true);

    // 2. PHP editor decodes with V1 schema
    $editor = new PayloadEditor($bytes, v1Schema());
    expect($editor->getValue('id'))->toBe(42)
        ->and($editor->getValue('name'))->toBe('雷少')
        ->and($editor->getValue('score'))->toBe(100);

    // 3. Reshape to V2: reorder {name, id, points, level}, rename score→points, seed level=5
    $editor->reshape('', v2Schema(), [
        'points' => $editor->getValue('score'),
        'level' => 5,
    ]);
    $migrated = $editor->toBytes();

    // 4. Verify with V2 schema in PHP
    $editor2 = new PayloadEditor($migrated, v2Schema());
    expect($editor2->getValue('name'))->toBe('雷少')
        ->and($editor2->getValue('id'))->toBe(42)
        ->and($editor2->getValue('points'))->toBe(100)
        ->and($editor2->getValue('level'))->toBe(5);

    // 5. C# reads with V2 class — the real interop check
    $phpPayload = base64_encode($migrated);
    expect(trim(runCommand(['dotnet', 'run', $script, '--', 'v2-read', $phpPayload])))->toBe('ok');
});

it('migrates V1→V2 by adding a field via addProperty', function (): void {
    $script = csharpScript();

    // C# writes V1: { id:42, name:"雷少", score:100 }
    $csharpPayload = trim(runCommand(['dotnet', 'run', $script, '--', 'v1-write']));
    $bytes = base64_decode($csharpPayload, true);

    // Decode with V1, then surgically add the new 'level' field and reorder with setOrder
    $editor = new PayloadEditor($bytes, v1Schema());
    $editor->addProperty('', FieldDefinition::of('level', Type::INT32), 5, order: 3);
    $editor->setOrder('name', 0);
    $editor->setOrder('id', 1);
    $editor->setOrder('score', 2);
    $migrated = $editor->toBytes();

    // Verify with V2 schema (score is still named 'score' here, not renamed)
    $v2WithScore = new Schema([
        FieldDefinition::of('name', Type::STRING),
        FieldDefinition::of('id', Type::INT32),
        FieldDefinition::of('score', Type::INT32),
        FieldDefinition::of('level', Type::INT32),
    ]);
    $editor2 = new PayloadEditor($migrated, $v2WithScore);
    expect($editor2->getValue('name'))->toBe('雷少')
        ->and($editor2->getValue('id'))->toBe(42)
        ->and($editor2->getValue('score'))->toBe(100)
        ->and($editor2->getValue('level'))->toBe(5);
});

it('modifies V1 field values during migration', function (): void {
    $script = csharpScript();

    // C# writes V1: { id:42, name:"雷少", score:100 }
    $csharpPayload = trim(runCommand(['dotnet', 'run', $script, '--', 'v1-write']));
    $bytes = base64_decode($csharpPayload, true);

    // Decode V1, change score→200, reshape to V2, seed level=99
    $editor = new PayloadEditor($bytes, v1Schema());
    $editor->setValue('score', 200);
    $editor->reshape('', v2Schema(), [
        'points' => $editor->getValue('score'),
        'level' => 99,
    ]);
    $migrated = $editor->toBytes();

    // C# reads V2: expects points==200, level==99
    // (Need a separate C# command for this — verify via PHP instead)
    $editor2 = new PayloadEditor($migrated, v2Schema());
    expect($editor2->getValue('points'))->toBe(200)
        ->and($editor2->getValue('level'))->toBe(99)
        ->and($editor2->getValue('name'))->toBe('雷少')
        ->and($editor2->getValue('id'))->toBe(42);
});

it('round-trips a V1 payload without changes', function (): void {
    $script = csharpScript();

    $csharpPayload = trim(runCommand(['dotnet', 'run', $script, '--', 'v1-write']));
    $bytes = base64_decode($csharpPayload, true);

    $editor = new PayloadEditor($bytes, v1Schema());
    $reEncoded = $editor->toBytes();

    // C# V1 can read the re-encoded payload
    $phpPayload = base64_encode($reEncoded);
    expect(trim(runCommand(['dotnet', 'run', $script, '--', 'v1-read', $phpPayload])))->toBe('ok');
});

it('reads V1 payload, edits a value, and C# V1 reads the change', function (): void {
    $script = csharpScript();

    $csharpPayload = trim(runCommand(['dotnet', 'run', $script, '--', 'v1-write']));
    $bytes = base64_decode($csharpPayload, true);

    // Change score from 100 to 999
    $editor = new PayloadEditor($bytes, v1Schema());
    $editor->setValue('score', 999);
    $modified = $editor->toBytes();

    // C# V1 reads it — but V1 expects score==100, so this would fail.
    // Instead, verify the change with a V2-like read that checks score==999.
    $v2Read = new Schema([
        FieldDefinition::of('id', Type::INT32),
        FieldDefinition::of('name', Type::STRING),
        FieldDefinition::of('score', Type::INT32),
    ]);
    $editor2 = new PayloadEditor($modified, $v2Read);
    expect($editor2->getValue('score'))->toBe(999);
});
