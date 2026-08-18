<?php

declare(strict_types=1);

namespace Quiote\Support\Random;

/**
 * The real source of entropy: {@see bytes()}/{@see int()} answer from PHP's
 * CSPRNG exactly like `random_bytes()`/`random_int()` always did. This is what
 * the container binds {@see RandomnessInterface} to by default; nothing here
 * is mockable, which is the point -- tests reach for {@see SeededRandomness}
 * instead of stubbing this class.
 */
final class SystemRandomness implements RandomnessInterface
{
    /** @param positive-int $length */
    public function bytes(int $length): string
    {
        return random_bytes($length);
    }

    public function int(int $min, int $max): int
    {
        return random_int($min, $max);
    }
}
