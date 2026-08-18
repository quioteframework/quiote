<?php

declare(strict_types=1);

namespace Quiote\Support\Random;

/**
 * The one seam every direct random_bytes()/random_int() call site on the
 * request path is meant to go through instead. Mirrors
 * {@see \Quiote\Support\Clock\ClockInterface}'s role for `time()`: production
 * gets {@see SystemRandomness}, a test or a replay engine swaps in
 * {@see SeededRandomness} so a session id, a correlation id or a CSRF token
 * comes out the same on every run.
 *
 * Deliberately just two primitives -- raw bytes and a bounded integer -- since
 * every current call site reduces to one or the other (a byte string that gets
 * base64/hex-encoded, or a probability roll).
 */
interface RandomnessInterface
{
    /**
     * $length cryptographically-random-shaped bytes. Replaces a direct
     * `random_bytes($length)` call.
     *
     * @param positive-int $length
     */
    public function bytes(int $length): string;

    /**
     * A random integer in the inclusive range [$min, $max]. Replaces a direct
     * `random_int($min, $max)` call.
     */
    public function int(int $min, int $max): int;
}
