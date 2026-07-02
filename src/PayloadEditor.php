<?php

declare(strict_types=1);

namespace MemoryPack\Editor;

use MemoryPack\Core\MemoryPackReader;
use MemoryPack\Core\MemoryPackWriter;
use MemoryPack\Exception\MemoryPackException;
use MemoryPack\Formatters\FormatterRegistry;
use MemoryPack\Mapping\FieldDefinition;
use MemoryPack\Mapping\MemoryPackableInterface;
use MemoryPack\Mapping\Schema;
use MemoryPack\Mapping\SchemaFactory;
use MemoryPack\Mapping\Type;
use MemoryPack\MemoryPackSerializer;

/**
 * Edits a MemoryPack payload as a mutable, schema-driven tree without binding it
 * back to PHP classes. Decode with the schema the bytes were written with, mutate
 * the structure (change values, add or remove fields, reorder, change nested
 * shapes), then re-encode with toBytes().
 *
 * Re-encoding walks the edited tree and never re-derives schema from a class, so
 * structural edits survive. Use this for data migrations when appending a field
 * is not enough: changing a field's type, removing a middle field, or inserting a
 * field ahead of an existing one.
 *
 * Leaves (scalars, string, list, dict, custom formatters) are held
 * as decoded PHP values. Object fields become nested editable nodes. A class that
 * implements MemoryPackableInterface is opaque: its raw bytes are captured and can
 * be replaced wholesale but not descended into.
 */
final class PayloadEditor
{
    private readonly ObjectNode $root;

    private readonly FormatterRegistry $registry;

    private readonly SchemaFactory $schemaFactory;

    /**
     * @param list<FieldDefinition>|Schema $schema the schema the payload was written with
     */
    public function __construct(string $payload, array|Schema $schema)
    {
        $this->registry = MemoryPackSerializer::registry();
        $this->schemaFactory = MemoryPackSerializer::schemaFactory();

        $schema = $schema instanceof Schema ? $schema : new Schema($schema);
        $reader = new MemoryPackReader($payload);
        $root = $this->decodeObject($reader, $schema, !$schema->valueType);
        if ($root === null) {
            throw new MemoryPackException('Payload is a null object and cannot be edited.');
        }
        if ($reader->remaining() !== 0) {
            throw new MemoryPackException('Payload has trailing bytes.');
        }

        $this->root = $root;
    }

    public function has(string $path): bool
    {
        try {
            [$node, $name] = $this->locate($path);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return $node->has($name);
    }

    /**
     * Read a value. Leaves return their PHP value; object fields return a nested
     * associative array (see toArray()).
     */
    public function getValue(string $path): mixed
    {
        [$node, $name] = $this->locate($path);

        return $this->exportEntry($node->definition($name), $node->value($name));
    }

    public function setValue(string $path, mixed $value): self
    {
        [$node, $name] = $this->locate($path);
        $node->setValue($name, $this->importEntry($node->definition($name), $value));

        return $this;
    }

    /**
     * Read, transform, and write a value in one step: replace('b.c', fn ($old) => $old + 1).
     */
    public function replace(string $path, callable $transform): self
    {
        return $this->setValue($path, $transform($this->getValue($path)));
    }

    /**
     * Add a field to the object at $parentPath (use '' for the root). $order is the
     * 0-based wire position; omit to append. Pass order: 0 to insert ahead of all
     * existing fields.
     */
    public function addProperty(string $parentPath, FieldDefinition $definition, mixed $value, int|null $order = null): self
    {
        $node = $this->resolveObject($parentPath);
        $node->add($definition->name, $definition, $this->importEntry($definition, $value), $order);

        return $this;
    }

    public function removeProperty(string $path): self
    {
        [$node, $name] = $this->locate($path);
        $node->remove($name);

        return $this;
    }

    /**
     * Move an existing field to a new 0-based wire position.
     */
    public function setOrder(string $path, int $order): self
    {
        [$node, $name] = $this->locate($path);
        $node->move($name, $order);

        return $this;
    }

    /**
     * Reshape the object at $path to a new schema. Existing members are carried
     * over by name (keeping their decoded value, re-encoded with the new field's
     * type and formatter), members absent from the new shape are dropped, and new
     * members take their value from $values (or null). The new field order becomes
     * the wire order. This is the high-level migration helper: turn [a1, a2] into
     * [a2, a3, a1, a4] in one call instead of add/remove/setOrder.
     *
     * The shape may be a Schema, a class name, or a list whose entries are each a
     * FieldDefinition, a plain field name (string) reusing the field's current
     * definition, or an array spec ['name' => ..., 'type' => ..., 'nullable' => ...,
     * 'formatter' => ..., 'class' => ..., 'element' => ...,
     * 'key' => ..., 'value' => ...]. The optional 'value' seeds a new field.
     *
     * @param list<FieldDefinition|array<string, mixed>|string>|class-string|Schema $shape
     * @param array<string, mixed> $values values for new fields, keyed by field name
     */
    public function reshape(string $path, array|string|Schema $shape, array $values = []): self
    {
        $node = $this->resolveObject($path);

        $members = [];
        foreach ($this->shapeFields($shape, $node) as $entry) {
            $field = $entry['def'];
            if ($node->has($field->name)) {
                $value = $node->value($field->name);
            } else {
                $value = $this->importEntry($field, $entry['value'] ?? $values[$field->name] ?? null);
            }
            $members[$field->name] = ['def' => $field, 'value' => $value];
        }
        $node->setMembers($members);

        return $this;
    }

    /**
     * Normalize a reshape shape into a list of field definitions plus any inline
     * value seed. String entries reuse the node's current definition for that name.
     *
     * @param list<FieldDefinition|array<string, mixed>|string>|class-string|Schema $shape
     * @return list<array{def: FieldDefinition, value?: mixed}>
     */
    private function shapeFields(array|string|Schema $shape, ObjectNode $node): array
    {
        if ($shape instanceof Schema) {
            return array_map(static fn (FieldDefinition $f): array => ['def' => $f], $shape->fields);
        }
        if (is_string($shape)) {
            return array_map(static fn (FieldDefinition $f): array => ['def' => $f], $this->schemaFactory->create($shape)->fields);
        }

        return array_map(fn (FieldDefinition|array|string $entry): array => $this->shapeField($entry, $node), $shape);
    }

    /**
     * @param FieldDefinition|array<string, mixed>|string $entry
     * @return array{def: FieldDefinition, value?: mixed}
     */
    private function shapeField(FieldDefinition|array|string $entry, ObjectNode $node): array
    {
        if ($entry instanceof FieldDefinition) {
            return ['def' => $entry];
        }
        if (is_string($entry)) {
            if (!$node->has($entry)) {
                throw new \InvalidArgumentException("Cannot reuse field {$entry}: it is not present; give an array spec with a type.");
            }

            return ['def' => $node->definition($entry)];
        }

        $field = $this->fieldFromArray($entry);

        return array_key_exists('value', $entry) ? ['def' => $field, 'value' => $entry['value']] : ['def' => $field];
    }

    /**
     * Build a FieldDefinition from an array spec. 'element' and 'key' may themselves
     * be a FieldDefinition or a nested array spec.
     *
     * @param array<string, mixed> $spec
     */
    private function fieldFromArray(array $spec): FieldDefinition
    {
        $name = $spec['name'] ?? throw new \InvalidArgumentException('Field spec needs a name.');
        $type = $spec['type'] ?? throw new \InvalidArgumentException("Field spec {$name} needs a type.");

        return new FieldDefinition(
            $name,
            $type,
            $spec['nullable'] ?? false,
            isset($spec['element']) ? $this->nestedFieldSpec($spec['element']) : null,
            isset($spec['key']) ? $this->nestedFieldSpec($spec['key']) : null,
            $spec['formatter'] ?? null,
            $spec['class'] ?? null,
            $spec['valueType'] ?? false,
            $spec['propertyName'] ?? $name,
        );
    }

    /**
     * @param FieldDefinition|array<string, mixed> $spec
     */
    private function nestedFieldSpec(FieldDefinition|array $spec): FieldDefinition
    {
        return $spec instanceof FieldDefinition ? $spec : $this->fieldFromArray($spec);
    }

    public function toBytes(): string
    {
        $writer = new MemoryPackWriter();
        $this->encodeObject($writer, $this->root);

        return $writer->bytes();
    }

    /**
     * The whole tree as a nested associative array, handy for assertions.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->exportObject($this->root);
    }

    /**
     * The whole tree as a nested stdClass object.
     */
    public function toObject(): \stdClass
    {
        return $this->exportObjectValue($this->root);
    }

    public function toJson(int $flags = JSON_THROW_ON_ERROR, int $depth = 512): string
    {
        return json_encode($this->toArray(), $flags, $depth);
    }

    // --- decode ---------------------------------------------------------------

    private function decodeObject(MemoryPackReader $reader, Schema $schema, bool $readHeader): ObjectNode|null
    {
        $memberCount = count($schema->fields);
        if ($readHeader) {
            $memberCount = $reader->readObjectHeader();
            if ($memberCount === null) {
                return null;
            }
        }
        if ($memberCount > count($schema->fields)) {
            throw new MemoryPackException('Payload has more members than the schema can read.');
        }

        $node = new ObjectNode($readHeader);
        foreach ($schema->fields as $index => $field) {
            if ($index >= $memberCount) {
                $node->add($field->name, $field, null);
                continue;
            }
            $node->add($field->name, $field, $this->decodeField($reader, $field));
        }

        return $node;
    }

    private function decodeField(MemoryPackReader $reader, FieldDefinition $field): mixed
    {
        if ($field->type !== Type::OBJECT) {
            return $this->registry->resolve($field)->deserialize($reader, $field, $this->registry);
        }

        if ($field->className === null) {
            throw new \InvalidArgumentException("Object field {$field->name} needs a class name.");
        }
        if (is_subclass_of($field->className, MemoryPackableInterface::class)) {
            return $this->captureOpaque($reader, $field->className);
        }

        $schema = $this->schemaFactory->create($field->className);

        return $this->decodeObject($reader, $schema, !$field->valueType && !$schema->valueType);
    }

    /**
     * A self-serializing field owns its bytes. Decode it through its own class and
     * keep the instance; re-encoding runs the same class so the bytes are reproduced.
     */
    private function captureOpaque(MemoryPackReader $reader, string $className): OpaqueValue
    {
        $value = $className::memoryPackDeserialize($reader);

        return new OpaqueValue($className, $value);
    }

    // --- encode ---------------------------------------------------------------

    private function encodeObject(MemoryPackWriter $writer, ObjectNode $node): void
    {
        if ($node->writeHeader) {
            $writer->writeObjectHeader($node->count());
        }
        foreach ($node->entries() as $entry) {
            $this->encodeField($writer, $entry['def'], $entry['value']);
        }
    }

    private function encodeField(MemoryPackWriter $writer, FieldDefinition $field, mixed $value): void
    {
        if ($field->type !== Type::OBJECT) {
            $this->registry->resolve($field)->serialize($writer, $value, $field, $this->registry);
            return;
        }

        if ($value === null) {
            if (!$field->nullable) {
                throw new \InvalidArgumentException("Field {$field->name} cannot be null.");
            }
            $writer->writeNullObject();
            return;
        }
        if ($value instanceof OpaqueValue) {
            $value->className::memoryPackSerialize($writer, $value->value);
            return;
        }
        if (!$value instanceof ObjectNode) {
            throw new \InvalidArgumentException("Object field {$field->name} holds an unexpected value.");
        }

        $this->encodeObject($writer, $value);
    }

    // --- value import / export ------------------------------------------------

    /**
     * Convert a user-supplied value into the editor's internal representation for a
     * field. Object fields accept a nested array (built into a node via the field's
     * class schema) or an already-built node.
     */
    private function importEntry(FieldDefinition $field, mixed $value): mixed
    {
        if ($field->type !== Type::OBJECT || $value === null) {
            return $value;
        }
        if ($value instanceof ObjectNode || $value instanceof OpaqueValue) {
            return $value;
        }
        if ($field->className === null) {
            throw new \InvalidArgumentException("Object field {$field->name} needs a class name.");
        }
        if (is_subclass_of($field->className, MemoryPackableInterface::class)) {
            throw new \InvalidArgumentException("Field {$field->name} is self-serializing; assign an OpaqueValue.");
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException("Object field {$field->name} expects an array or ObjectNode.");
        }

        $schema = $this->schemaFactory->create($field->className);

        return $this->buildNode($schema, !$field->valueType && !$schema->valueType, $value);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function buildNode(Schema $schema, bool $writeHeader, array $values): ObjectNode
    {
        $node = new ObjectNode($writeHeader);
        foreach ($schema->fields as $field) {
            $node->add($field->name, $field, $this->importEntry($field, $values[$field->name] ?? null));
        }

        return $node;
    }

    private function exportEntry(FieldDefinition $field, mixed $value): mixed
    {
        if ($value instanceof ObjectNode) {
            return $this->exportObject($value);
        }
        if ($value instanceof OpaqueValue) {
            return $value->value;
        }

        return $value;
    }

    private function exportEntryValue(FieldDefinition $field, mixed $value): mixed
    {
        if ($value instanceof ObjectNode) {
            return $this->exportObjectValue($value);
        }
        if ($value instanceof OpaqueValue) {
            return $value->value;
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function exportObject(ObjectNode $node): array
    {
        $result = [];
        foreach ($node->entries() as $entry) {
            $result[$entry['def']->name] = $this->exportEntry($entry['def'], $entry['value']);
        }

        return $result;
    }

    private function exportObjectValue(ObjectNode $node): \stdClass
    {
        $result = new \stdClass();
        foreach ($node->entries() as $entry) {
            $result->{$entry['def']->name} = $this->exportEntryValue($entry['def'], $entry['value']);
        }

        return $result;
    }

    // --- path resolution ------------------------------------------------------

    /**
     * Resolve a dotted path to the owning node and the final segment name.
     *
     * @return array{0: ObjectNode, 1: string}
     */
    private function locate(string $path): array
    {
        $segments = $this->segments($path);
        if ($segments === []) {
            throw new \InvalidArgumentException('Path cannot be empty.');
        }

        $name = array_pop($segments);
        $node = $this->descend($this->root, $segments);

        return [$node, $name];
    }

    private function resolveObject(string $path): ObjectNode
    {
        return $this->descend($this->root, $this->segments($path));
    }

    /**
     * @param list<string> $segments
     */
    private function descend(ObjectNode $node, array $segments): ObjectNode
    {
        foreach ($segments as $segment) {
            $value = $node->value($segment);
            if (!$value instanceof ObjectNode) {
                throw new \InvalidArgumentException("Path segment {$segment} is not an editable object.");
            }
            $node = $value;
        }

        return $node;
    }

    /**
     * @return list<string>
     */
    private function segments(string $path): array
    {
        if ($path === '') {
            return [];
        }

        return explode('.', $path);
    }
}
