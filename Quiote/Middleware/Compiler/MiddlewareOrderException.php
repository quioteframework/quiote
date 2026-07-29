<?php
declare(strict_types=1);

namespace Quiote\Middleware\Compiler;

/**
 * Thrown by MiddlewareOrderResolver when a `#[Middleware]` ordering constraint
 * cannot be honoured and there is no safe fallback: either the `before`/`after`
 * constraints form a cycle, or a *guarded* (framework) middleware's constraint
 * names something that isn't there.
 *
 * An unresolvable reference on app or plugin middleware still degrades to a
 * Diagnostic and is skipped -- anchoring to an optional package's middleware is
 * a legitimate pattern, and that middleware simply falls back to its phase and
 * priority. For framework middleware it is not survivable: those constraints are
 * how a security check's position in the pipeline is guaranteed at all, so
 * silently dropping one turns "CSRF runs before dispatch" into "CSRF happens to
 * run before dispatch, given the current priorities".
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

    /**
     * @param string $from The guarded middleware carrying the constraint.
     * @param string $reference The unresolvable before/after target as written.
     * @param string $why What was wrong with it (unknown, or ambiguous).
     */
    public static function unresolvedGuardedReference(string $from, string $reference, string $why): self
    {
        return new self(sprintf(
            'Framework middleware "%s" declares a before/after constraint on "%s", which %s. '
            . 'That constraint is what fixes this middleware\'s position in the pipeline, so it cannot '
            . 'be dropped: dropping it would silently fall back to phase/priority ordering and leave a '
            . 'framework guarantee (e.g. "CSRF validation runs before dispatch") holding only by accident. '
            . 'Either the class is missing from the scan or the reference is misspelled.',
            $from,
            $reference,
            $why
        ));
    }
}
