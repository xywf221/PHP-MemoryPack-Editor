<?php

declare(strict_types=1);

namespace MemoryPack\Editor;

/**
 * Holds a self-serializing (MemoryPackableInterface) field inside an edited tree.
 * The editor cannot descend into such a field, so it keeps the decoded object and
 * re-serializes it through the same class on the way out. Assign a fresh instance
 * to replace it wholesale.
 *
 * @internal
 */
final class OpaqueValue
{
    /**
     * @param class-string $className
     */
    public function __construct(
        public readonly string $className,
        public readonly object|null $value,
    ) {
    }
}
