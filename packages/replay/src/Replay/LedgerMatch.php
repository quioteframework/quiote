<?php

declare(strict_types=1);

namespace Quiote\Replay\Replay;

use Quiote\Replay\Cassette\Effect;

/**
 * What {@see LedgerMatcher} found, and how it found it.
 *
 * The distinction is the whole point of the type: a fingerprint match is the recorded
 * counterpart of the call being replayed, while a sequence match is only the next unconsumed
 * effect of the same kind -- a different call's recorded result, handed over because nothing
 * better was available. Returning a bare {@see Effect} made the two indistinguishable to the
 * caller, so a stub could not refuse the second and a drift report could not mention it.
 */
final readonly class LedgerMatch
{
    private function __construct(
        public Effect $effect,
        public bool $fuzzy,
    ) {
    }

    /** The recorded effect whose fingerprint is identical to the replayed call's. */
    public static function exact(Effect $effect): self
    {
        return new self($effect, false);
    }

    /**
     * The next unconsumed effect of the right kind, whose fingerprint does *not* match. Its
     * result belongs to a different call.
     */
    public static function fuzzy(Effect $effect): self
    {
        return new self($effect, true);
    }
}
