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
    // Default: cache enabled for all output types unless overridden.
    public function isCacheable(?string $outputType = null): bool { return true; }
    public function cacheTtlSeconds(?string $outputType = null): ?int { return 300; }
}
