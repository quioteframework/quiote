<?php

declare(strict_types=1);

namespace Quiote\Support\Environment;

/**
 * The one seam a direct `getenv()` call site on the request path is meant to
 * go through instead, mirroring {@see \Quiote\Support\Clock\ClockInterface}'s
 * and {@see \Quiote\Support\Random\RandomnessInterface}'s role for `time()`
 * and `random_bytes()`: production gets {@see SystemEnvironmentReader}, a
 * replay engine swaps in a stub answering from a recorded effect ledger.
 *
 * The return type matches `getenv()`'s own contract exactly -- `false` for an
 * unset variable -- rather than a nullable string, so a caller migrating from
 * a bare `getenv()` call needs no change beyond the collaborator it reads
 * through.
 */
interface EnvironmentReaderInterface
{
    /** The value of environment variable $name, or false when it is unset. */
    public function get(string $name): string|false;
}
