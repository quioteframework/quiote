<?php
namespace Quiote\Action\Traits;

/**
 * Opt-in PSR cache support for actions.
 * Usage: use CacheableActionTrait; override cacheTtlSeconds() or isCacheable().
 */
trait CacheableActionTrait
{
    // Default: cache enabled for all output types unless overridden.
    public function isCacheable(?string $outputType = null): bool { return true; }
    public function cacheTtlSeconds(?string $outputType = null): ?int { return 300; }
}
