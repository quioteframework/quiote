<?php

declare(strict_types=1);

namespace Quiote\Replay\Replay;

use Quiote\Replay\Cassette\Effect;
use Quiote\Replay\Cassette\EffectKind;

/**
 * A request's effect ledger: written to by appending during recording, read
 * from by matching during replay. One instance serves exactly one direction
 * at a time -- a fresh ledger for recording starts empty and only ever calls
 * {@see record()}; a ledger for replay is constructed from a cassette's
 * stored effects and only ever calls {@see match()}.
 *
 * Match/miss accounting falls out of three queries answerable at any point
 * during replay: {@see misses()} is every
 * call replay asked for that had no recorded counterpart -- the code now
 * does something it did not do when recorded -- {@see unplayed()} is
 * every recorded effect nothing asked for -- the code no longer does
 * something it used to -- and {@see fuzzyMatches()} is every call answered
 * from a recorded effect with a *different* fingerprint, which is a weaker
 * claim than a match and worth reporting as such. All three are diagnostics,
 * not exceptions; a stub built on
 * top of this class (e.g. `StubbedPdo`) decides what a miss means for its own
 * subsystem, typically raising in isolated mode rather than inventing a
 * result.
 */
final class EffectLedger
{
    /** @var list<Effect> */
    private array $effects;

    /** @var array<non-negative-int, true> */
    private array $consumedSeqs = [];

    /** @var list<array{kind: EffectKind, fingerprint: string}> */
    private array $misses = [];

    /** @var list<array{kind: EffectKind, fingerprint: string, matched: string}> */
    private array $fuzzyMatches = [];

    /** @param list<Effect> $effects Effects loaded from a cassette, in original recorded order. */
    public function __construct(array $effects = [])
    {
        $this->effects = $effects;
    }

    /**
     * Appends a freshly observed effect, assigning it the next sequence
     * number. Used while recording.
     *
     * @param array<string, mixed> $call
     * @param non-negative-int|null $durationMicros
     */
    public function record(EffectKind $kind, string $fingerprint, array $call, mixed $result, ?int $durationMicros = null): Effect
    {
        $effect = new Effect(count($this->effects), $kind, $fingerprint, $call, $result, $durationMicros);
        $this->effects[] = $effect;

        return $effect;
    }

    /**
     * Consumes and returns the best-matching recorded effect for a replayed
     * call (see {@see LedgerMatcher}), or null on a miss. A miss is recorded
     * for {@see misses()} regardless of what the caller does with the null.
     *
     * `$allowFuzzy` decides what happens when the matcher can only offer a sequence match -- the
     * next unconsumed effect of the right kind, carrying a *different* call's recorded result.
     * It defaults to refusing that: a stub answering a read from it would hand the caller data
     * that belongs to another call, which is indistinguishable from a correct answer and is how
     * an isolated replay ends up passing on fabricated input. A refused fuzzy match is recorded
     * as a miss, so the drift it represents is reported rather than smoothed over.
     *
     * Pass `true` where a fingerprint genuinely cannot be recomputed identically across runs and
     * positional matching is the intended semantics. The match is then recorded in
     * {@see fuzzyMatches()} so a drift report can still say the answer was approximate.
     */
    public function match(EffectKind $kind, string $fingerprint, bool $allowFuzzy = false): ?Effect
    {
        $match = LedgerMatcher::match($this->effects, $this->consumedSeqs, $kind, $fingerprint);
        if ($match === null) {
            $this->misses[] = ['kind' => $kind, 'fingerprint' => $fingerprint];

            return null;
        }

        if ($match->fuzzy) {
            if (!$allowFuzzy) {
                $this->misses[] = ['kind' => $kind, 'fingerprint' => $fingerprint];

                return null;
            }
            $this->fuzzyMatches[] = [
                'kind' => $kind,
                'fingerprint' => $fingerprint,
                'matched' => $match->effect->fingerprint,
            ];
        }

        $this->consumedSeqs[$match->effect->seq] = true;

        return $match->effect;
    }

    /** @return list<Effect> Recorded effects nothing during replay ever asked for. */
    public function unplayed(): array
    {
        return array_values(array_filter(
            $this->effects,
            fn(Effect $effect): bool => !isset($this->consumedSeqs[$effect->seq]),
        ));
    }

    /** @return list<array{kind: EffectKind, fingerprint: string}> Replayed calls that had no recorded counterpart. */
    public function misses(): array
    {
        return $this->misses;
    }

    /**
     * Replayed calls answered from a recorded effect whose fingerprint differed -- matched by
     * position within their kind, not by identity. Only ever populated when a caller opted into
     * a fuzzy match; `matched` names the fingerprint the answer actually came from.
     *
     * @return list<array{kind: EffectKind, fingerprint: string, matched: string}>
     */
    public function fuzzyMatches(): array
    {
        return $this->fuzzyMatches;
    }

    /** @return list<Effect> Every effect this ledger holds, in original recorded order. */
    public function all(): array
    {
        return $this->effects;
    }
}
