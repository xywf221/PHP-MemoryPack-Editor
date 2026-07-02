<?php

declare(strict_types=1);

namespace MemoryPack\Editor;

use MemoryPack\Mapping\FieldDefinition;

/**
 * A mutable object node in a decoded payload tree. Members are kept in wire
 * order; each carries the FieldDefinition it was decoded with so the editor can
 * re-encode without consulting SchemaFactory (which would clobber structural
 * edits). This is internal to PayloadEditor.
 *
 * @internal
 */
final class ObjectNode
{
    /**
     * Ordered map of member name to its definition and current value.
     *
     * @var array<string, array{def: FieldDefinition, value: mixed}>
     */
    private array $members = [];

    public function __construct(public readonly bool $writeHeader)
    {
    }

    public function has(string $name): bool
    {
        return isset($this->members[$name]);
    }

    public function names(): array
    {
        return array_keys($this->members);
    }

    public function count(): int
    {
        return count($this->members);
    }

    public function definition(string $name): FieldDefinition
    {
        return $this->member($name)['def'];
    }

    public function value(string $name): mixed
    {
        return $this->member($name)['value'];
    }

    /**
     * @return list<array{def: FieldDefinition, value: mixed}>
     */
    public function entries(): array
    {
        return array_values($this->members);
    }

    public function setValue(string $name, mixed $value): void
    {
        $this->member($name);
        $this->members[$name]['value'] = $value;
    }

    /**
     * Append a member, or insert it at $index (0-based) when given. Throws if a
     * member with the same name already exists.
     */
    public function add(string $name, FieldDefinition $def, mixed $value, int|null $index = null): void
    {
        if (isset($this->members[$name])) {
            throw new \InvalidArgumentException("Member {$name} already exists.");
        }

        $entry = ['def' => $def, 'value' => $value];
        if ($index === null || $index >= count($this->members)) {
            $this->members[$name] = $entry;
            return;
        }

        $this->members = $this->insertAt($this->members, $name, $entry, $index);
    }

    public function remove(string $name): void
    {
        $this->member($name);
        unset($this->members[$name]);
    }

    /**
     * Replace the whole ordered member set, e.g. when reshaping to a new schema.
     *
     * @param array<string, array{def: FieldDefinition, value: mixed}> $members
     */
    public function setMembers(array $members): void
    {
        $this->members = $members;
    }

    /**
     * Move an existing member to $index (0-based) in wire order.
     */
    public function move(string $name, int $index): void
    {
        $entry = $this->member($name);
        unset($this->members[$name]);
        $this->members = $this->insertAt($this->members, $name, $entry, $index);
    }

    /**
     * @return array{def: FieldDefinition, value: mixed}
     */
    private function member(string $name): array
    {
        return $this->members[$name] ?? throw new \InvalidArgumentException("Unknown member {$name}.");
    }

    /**
     * @param array<string, array{def: FieldDefinition, value: mixed}> $members
     * @param array{def: FieldDefinition, value: mixed} $entry
     * @return array<string, array{def: FieldDefinition, value: mixed}>
     */
    private function insertAt(array $members, string $name, array $entry, int $index): array
    {
        $index = max(0, min($index, count($members)));
        $result = [];
        $position = 0;
        foreach ($members as $key => $existing) {
            if ($position === $index) {
                $result[$name] = $entry;
            }
            $result[$key] = $existing;
            $position++;
        }
        if ($position <= $index) {
            $result[$name] = $entry;
        }

        return $result;
    }
}
