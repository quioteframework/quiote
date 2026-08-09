<?php

declare(strict_types=1);

namespace Quiote\Docs\Ir;

/** One case of an enum, with its backing value when it has one. */
final class EnumCaseDoc
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $backingValue,
        public readonly DocBlock $doc,
    ) {
    }
}
