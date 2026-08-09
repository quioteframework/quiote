<?php

declare(strict_types=1);

namespace Quiote\Docs\Ir;

/** One property, including a constructor-promoted one. */
final class PropertyDoc
{
    /** @param 'public'|'protected' $visibility */
    public function __construct(
        public readonly string $name,
        public readonly TypeRef $type,
        public readonly DocBlock $doc,
        public readonly string $visibility = 'public',
        public readonly bool $static = false,
        public readonly bool $readonly = false,
        public readonly bool $promoted = false,
        public readonly ?string $default = null,
    ) {
    }
}
