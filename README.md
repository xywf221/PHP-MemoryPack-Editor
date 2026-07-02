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

## Development

```bash
composer install
composer test
```
