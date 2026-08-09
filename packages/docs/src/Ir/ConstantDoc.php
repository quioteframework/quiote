<?php

declare(strict_types=1);

namespace Quiote\Docs\Ir;

/**
 * One public class constant.
 *
 * Constants are documented because they turn up as parameter defaults; omitting them
 * would leave those defaults pointing at nothing.
 */
final class ConstantDoc
{
    public function __construct(
        public readonly string $name,
        public readonly string $value,
        public readonly DocBlock $doc,
        public readonly bool $final = false,
    ) {
    }
}
