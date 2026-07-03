<?php
declare(strict_types=1);

namespace Quiote\Middleware\Compiler;

/**
 * Thrown by MiddlewareOrderResolver when the scanned `#[Middleware]`
 * `before`/`after` constraints form a cycle and no valid order exists.
 * Unlike unresolvable before/after references (which degrade to a
 * Diagnostic and are skipped), a cycle has no reasonable fallback — building
 * the pipeline must fail loudly rather than pick an arbitrary order.
 * @since      1.0.0
 */
final class MiddlewareOrderException extends \RuntimeException
{
    /**
     * @param string[] $involved FQCNs of the middleware still unordered when the cycle was detected.
     */
    public static function cycle(array $involved): self
    {
        return new self(sprintf(
            'Cannot resolve middleware order: a before/after cycle involves: %s. '
            . 'Check the #[Middleware] attributes on these classes for a contradictory chain.',
            implode(', ', $involved)
        ));
    }
}
