# PHP MemoryPack Editor

Payload editor and schema migration helpers for `memory-pack/php-memory-pack`.

Appending a nullable field at the end of a MemoryPack object is backward compatible. When a migration needs to change a field type, remove a middle field, insert a field ahead of an existing one, or reorder fields, use `PayloadEditor`.

```php
use MemoryPack\Editor\PayloadEditor;
use MemoryPack\Mapping\FieldDefinition;
use MemoryPack\Mapping\Type;

$editor = new PayloadEditor($payload, $oldSchema);

$editor->setValue('b.c', 5);
$editor->replace('b.c', fn ($old) => $old + 1);
$editor->addProperty('b', FieldDefinition::of('d', Type::INT32), value: 0, order: 0);
$editor->removeProperty('b.c');
$editor->setOrder('b.c', 0);

$newBytes = $editor->toBytes();
$tree = $editor->toArray();
$object = $editor->toObject();
$json = $editor->toJson();
```

Paths are dot-separated and descend through object fields; pass `''` for the root object. The `order` argument is the 0-based position in the object's field list, which is exactly the wire order.

## Reshape

Use `reshape()` when a migration changes several fields at once. It replaces the
field list for an object, carries existing values by field name, drops fields not
listed in the new shape, and seeds new fields from either the inline `value` or
the second argument.

```php
$bytes = (new PayloadEditor($payload, $oldSchema))
    ->reshape('', [
        'a2',
        ['name' => 'a3', 'type' => Type::INT32, 'value' => 3],
        'a1',
        ['name' => 'a4', 'type' => Type::STRING, 'nullable' => true],
    ], [
        'a4' => 'added',
    ])
    ->toBytes();
```

Shape entries may be:

- a field name string, which reuses an existing field definition
- a `FieldDefinition`
- an array spec with `name`, `type`, `nullable`, `formatter`, `class`,
  `element`, `key`, `valueType`, `propertyName`, and optional `value`

The tests for this behavior live in `tests/PayloadEditorReshapeTest.php`.

## Development

```bash
composer install
composer test
```
