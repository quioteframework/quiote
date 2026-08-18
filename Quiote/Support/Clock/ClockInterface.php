<?php

declare(strict_types=1);

namespace Quiote\Support\Clock;

use Psr\Clock\ClockInterface as PsrClockInterface;

/**
 * The one seam every direct time()/microtime()/new DateTime() call site in
 * core is meant to go through instead. Extends {@see PsrClockInterface} — whose
 * single `now()` method is the wall-clock reading most callers actually want —
 * with the two reads the timing- and expiry-sensitive code in this codebase
 * needs and PSR-Clock does not provide:
 *
 *  - {@see unixTimestamp()} and {@see microtime()} are wall-clock, exactly like
 *    `time()`/`microtime(true)`, for anything that stores or compares an
 *    epoch-relative expiry (a session's idle timeout, a cache TTL, a cookie's
 *    `Expires`).
 *  - {@see monotonic()} is deliberately not wall-clock: it never steps
 *    backwards on an NTP correction or a VM clock resync, which is what a
 *    duration measurement (a request's execution time, a connection's idle
 *    check) actually needs. Mixing the two up is the class of bug documented
 *    on {@see \Quiote\Session\SessionManager}'s `resolveRedirect()`.
 *
 * A test swaps in {@see FrozenClock} or {@see OffsetClock}; production gets
 * {@see SystemClock}.
 */
interface ClockInterface extends PsrClockInterface
{
    /**
     * The current wall-clock time.
     */
    public function now(): \DateTimeImmutable;

    /**
     * Wall-clock Unix timestamp in whole seconds. Replaces a direct `time()` call.
     */
    public function unixTimestamp(): int;

    /**
     * Wall-clock Unix timestamp with microsecond precision. Replaces a direct
     * `microtime(true)` call.
     */
    public function microtime(): float;

    /**
     * Seconds on a monotonic clock: immune to wall-clock steps, so only ever
     * meaningful as the difference between two readings. Replaces a direct
     * `hrtime(true)` call (or a `microtime(true)` one used for a duration
     * rather than a point in time).
     */
    public function monotonic(): float;
}
