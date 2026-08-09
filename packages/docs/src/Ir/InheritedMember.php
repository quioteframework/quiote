<?php

declare(strict_types=1);

namespace Quiote\Docs\Ir;

/**
 * A member a class gets from an ancestor, listed rather than documented.
 *
 * Inherited members outnumber declared ones several times over in this framework, so
 * repeating their documentation on every descendant would bury what each class actually
 * adds. A row pointing at the declaring type says the same thing in one line.
 */
final class InheritedMember
{
    public function __construct(
        public readonly string $name,
        public readonly string $declaredIn,
        public readonly string $summary = '',
    ) {
    }
}
