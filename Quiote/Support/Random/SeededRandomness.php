<?php

declare(strict_types=1);

namespace Quiote\Support\Random;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * A source of entropy that is not random at all: the same seed always
 * produces the same sequence of {@see bytes()}/{@see int()} results, in call
 * order. This is what a deterministic test of anything id- or token-shaped
 * wants -- a test asserting a specific generated session id, or a replay
 * engine reproducing a recorded CSRF token, should not depend on the real
 * CSPRNG happening to agree with what was recorded.
 *
 * Backed by {@see Randomizer} over a seeded {@see Mt19937} engine -- not
 * cryptographically secure, which is irrelevant here: the whole point is that
 * the sequence is reproducible, not that it is unguessable.
 */
final class SeededRandomness implements RandomnessInterface
{
    private readonly Randomizer $randomizer;

    public function __construct(int $seed)
    {
        $this->randomizer = new Randomizer(new Mt19937($seed));
    }

    /** @param positive-int $length */
    public function bytes(int $length): string
    {
        return $this->randomizer->getBytes($length);
    }

    public function int(int $min, int $max): int
    {
        return $this->randomizer->getInt($min, $max);
    }
}
