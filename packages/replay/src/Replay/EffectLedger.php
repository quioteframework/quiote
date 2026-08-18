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
 * Match/miss accounting (§7.2 of the record/replay plan) falls out of two
 * queries answerable at any point during replay: {@see misses()} is every
 * call replay asked for that had no recorded counterpart -- the code now
 * does something it did not do when recorded -- and {@see unplayed()} is
 * every recorded effect nothing asked for -- the code no longer does
 * something it used to. Both are diagnostics, not exceptions; a stub built on
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
     */
    public function match(EffectKind $kind, string $fingerprint): ?Effect
    {
        $effect = LedgerMatcher::match($this->effects, $this->consumedSeqs, $kind, $fingerprint);
        if ($effect === null) {
            $this->misses[] = ['kind' => $kind, 'fingerprint' => $fingerprint];

            return null;
        }
        $this->consumedSeqs[$effect->seq] = true;

        return $effect;
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

    /** @return list<Effect> Every effect this ledger holds, in original recorded order. */
    public function all(): array
    {
        return $this->effects;
    }
}
