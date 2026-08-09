<?php
namespace Quiote\Action\Traits;

/**
 * Opt-in PSR cache support for actions.
 * Usage: use CacheableActionTrait; override cacheTtlSeconds() or isCacheable().
 *
 * A secure action's cache is partitioned per user by default -- this trait does
 * not touch {@see \Quiote\Action\Action::cacheVaryByUser()}, so it inherits that
 * safe default. Override it to false only if the output really is identical for
 * every user allowed to reach the action.
 */
trait CacheableActionTrait
{
    /**
     * Reports that the action's response may be cached.
     *
     * Returns true for every output type. Override in the using action to
     * restrict caching to particular output types, or to disable it entirely
     * for a request whose result must always be recomputed.
     */
    public function isCacheable(?string $outputType = null): bool { return true; }

    /**
     * Returns the lifetime, in seconds, of a cached response for this action.
     *
     * Five minutes for every output type. Override in the using action to tune
     * the lifetime per output type, or return null to fall back to the
     * framework's own default lifetime handling.
     */
    public function cacheTtlSeconds(?string $outputType = null): ?int { return 300; }
}
