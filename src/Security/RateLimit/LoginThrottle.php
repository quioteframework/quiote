<?php

namespace Agavi\Security\RateLimit;

use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * A small login/auth throttle on top of symfony/rate-limiter.
 *
 * Intended for the "count failed authentication attempts per key (IP, username,
 * ...)" pattern: peek before doing expensive auth work, register a failure when
 * auth fails, and reset on success. Backed by any Symfony rate-limiter
 * StorageInterface — use {@see PdoRateLimiterStorage} to keep state in the
 * application database (no Redis required).
 *
 * Uses a sliding-window policy. Concurrency: without a LockFactory the window
 * may slightly over/under-count under simultaneous failures, which is harmless
 * for a brute-force throttle; pass a Symfony LockFactory (e.g. backed by a
 * PostgreSqlStore) if you need exactness.
 *
 * @package    agavi
 * @subpackage security
 */
final class LoginThrottle
{
    private RateLimiterFactory $factory;

    /**
     * @param StorageInterface $storage     Where window state is persisted.
     * @param int              $maxAttempts Allowed attempts within the interval.
     * @param string           $interval    Window size, e.g. "15 minutes" / "1 hour".
     * @param string           $id          Limiter id namespace (keep distinct per use-case).
     */
    public function __construct(
        StorageInterface $storage,
        int $maxAttempts = 10,
        string $interval = '15 minutes',
        string $id = 'agavi_login'
    ) {
        $this->factory = new RateLimiterFactory([
            'id' => $id,
            'policy' => 'sliding_window',
            'limit' => max(1, $maxAttempts),
            'interval' => $interval,
        ], $storage);
    }

    /**
     * Seconds the caller must wait if $key is currently exhausted, or null if it
     * is still allowed. Does NOT consume an attempt (peek only) — use this at the
     * start of request handling to reject flooding before doing expensive work.
     *
     * Note: a peek (consume(0)) is always "accepted" by the limiter, so we judge
     * exhaustion by remaining tokens rather than isAccepted().
     */
    public function retryAfter(string $key): ?int
    {
        $limit = $this->factory->create($key)->consume(0);
        if ($limit->getRemainingTokens() > 0) {
            return null;
        }
        return $this->secondsUntil($limit);
    }

    /**
     * Register a single failed attempt for $key. Returns the seconds to wait if
     * this failure exceeded the limit (request should be rejected), otherwise
     * null (counted, still within the allowance).
     */
    public function registerFailure(string $key): ?int
    {
        $limit = $this->factory->create($key)->consume(1);
        if ($limit->isAccepted()) {
            return null;
        }
        return $this->secondsUntil($limit);
    }

    /**
     * Clear the counter for $key. Call after a successful authentication so a
     * legitimate client is never penalised for earlier typos.
     */
    public function reset(string $key): void
    {
        $this->factory->create($key)->reset();
    }

    private function secondsUntil(RateLimit $limit): int
    {
        return max(1, $limit->getRetryAfter()->getTimestamp() - time());
    }
}
