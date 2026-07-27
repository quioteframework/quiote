<?php

namespace Quiote\Scheduler;

use Psr\SimpleCache\CacheInterface;

/**
 * Best-effort overlap-prevention lock for {@see ScheduledTaskDefinition::withoutOverlapping()},
 * built on the app's existing PSR-16 {@see CacheInterface} rather than a new
 * lock subsystem. PSR-16 has no atomic add-if-absent, so there is a narrow
 * TOCTOU race between concurrent `schedule:run` invocations' `has()` check
 * and `set()` call — acceptable for the common "still running past the next
 * minute" case this guards against, not a hardened distributed lock.
 */
final readonly class SchedulerLock
{
    public function __construct(private CacheInterface $cache)
    {
    }

    public function acquire(string $key, int $ttlSeconds): bool
    {
        if ($this->cache->has($key)) {
            return false;
        }

        $this->cache->set($key, true, $ttlSeconds);
        return true;
    }

    public function release(string $key): void
    {
        $this->cache->delete($key);
    }
}
