<?php
declare(strict_types=1);

namespace Quiote\Middleware\Compiler;

/**
 * The scanned contents of one `#[Middleware]` attribute, plus the class it
 * was found on and where it was discovered — mirrors
 * `Quiote\Routing\Compiler\RouteDefinition`'s role for `#[Route]`.
 * @since      1.0.0
 */
final class MiddlewareDefinition
{
    public function __construct(
        public readonly string $fqcn,
        public readonly string $phase,
        public readonly int $priority,
        public readonly ?string $before,
        public readonly ?string $after,
        public readonly bool $enabled,
        public readonly string $sourceRef,
    ) {
    }

    /** Short class name, used to resolve before/after references given as bare class names. */
    public function shortName(): string
    {
        $pos = strrpos($this->fqcn, '\\');
        return $pos === false ? $this->fqcn : substr($this->fqcn, $pos + 1);
    }
}
