<?php

declare(strict_types=1);

namespace MemoryPack\Editor\Tests\Fixtures;

use MemoryPack\Core\MemoryPackReader;
use MemoryPack\Core\MemoryPackWriter;
use MemoryPack\Formatters\FormatterRegistry;
use MemoryPack\Formatters\MemoryPackFormatterInterface;
use MemoryPack\Mapping\FieldDefinition;

final class ReverseStringFormatter implements MemoryPackFormatterInterface
{
    #[\Override]
    public function serialize(MemoryPackWriter $writer, mixed $value, FieldDefinition $field, FormatterRegistry $registry): void
    {
        $writer->writeString($value === null ? null : strrev((string) $value));
    }

    #[\Override]
    public function deserialize(MemoryPackReader $reader, FieldDefinition $field, FormatterRegistry $registry): mixed
    {
        $value = $reader->readString();

        return $value === null ? null : strrev($value);
    }
}
