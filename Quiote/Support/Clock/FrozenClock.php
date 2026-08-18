<?php

declare(strict_types=1);

namespace Quiote\Support\Clock;

/**
 * A clock that does not move except when told to: every read answers the
 * wall-clock/monotonic values last set, however much real time elapses
 * between two calls. This is what a deterministic test of anything
 * expiry-based (a session timeout, a cache TTL, a cookie's `Expires`) wants --
 * a test asserting "expired after N seconds" should not depend on how fast the
 * test runner happens to execute.
 *
 * Wall-clock time is kept as a float Unix timestamp rather than a
 * `DateTimeImmutable`, and {@see now()} is derived from it via the `@seconds`
 * constructor form (which -- since PHP 7.1 -- accepts a fractional part), the
 * same conversion {@see \Quiote\Validator\DateTimeValidator} already relies on
 * elsewhere in this codebase. The result is always UTC, matching what a bare
 * `new DateTimeImmutable('@...')` produces.
 */
final class FrozenClock implements ClockInterface
{
    public function __construct(
        private float $wallClockSeconds = 0.0,
        private float $monotonicSeconds = 0.0,
    ) {
    }

    /**
     * Build a FrozenClock frozen at $now, converted through its own timezone
     * so a caller working in local time gets the wall-clock second it expects.
     */
    public static function fromDateTime(\DateTimeInterface $now, float $monotonicSeconds = 0.0): self
    {
        return new self((float) $now->format('U.u'), $monotonicSeconds);
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('@%.6F', $this->wallClockSeconds));
    }

    public function unixTimestamp(): int
    {
        return (int) $this->wallClockSeconds;
    }

    public function microtime(): float
    {
        return $this->wallClockSeconds;
    }

    public function monotonic(): float
    {
        return $this->monotonicSeconds;
    }

    /**
     * Jump the wall clock to $wallClockSeconds, leaving the monotonic reading
     * untouched -- a wall-clock step (an NTP correction, a VM resync) is
     * exactly the scenario {@see ClockInterface::monotonic()} exists to be
     * immune to.
     */
    public function set(float $wallClockSeconds): void
    {
        $this->wallClockSeconds = $wallClockSeconds;
    }

    /** @see set() */
    public function setMonotonic(float $monotonicSeconds): void
    {
        $this->monotonicSeconds = $monotonicSeconds;
    }

    /**
     * Move both the wall clock and the monotonic clock forward by the same
     * amount, as real elapsed time would -- the common case for "then N
     * seconds pass" in a test, as opposed to {@see set()} simulating a clock
     * step where only the wall clock moves.
     */
    public function advance(float $seconds): void
    {
        $this->wallClockSeconds += $seconds;
        $this->monotonicSeconds += $seconds;
    }
}
