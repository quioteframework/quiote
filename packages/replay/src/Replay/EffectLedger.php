<?php

declare(strict_types=1);

namespace Quiote\Replay\Replay;

use Quiote\Replay\Cassette\Effect;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Recording\EffectRedactor;

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

    /** Payload bytes charged so far by {@see record()}, against {@see $maxPayloadBytes}. */
    private int $payloadBytesUsed = 0;

    private bool $payloadTruncated = false;

    /**
     * @param list<Effect> $effects Effects loaded from a cassette, in original recorded order.
     * @param int|null $maxPayloadBytes Ceiling on the total size of the `call`/`result` payloads
     *        {@see record()} keeps. Null means unbounded, for a ledger built from an
     *        already-bounded cassette on the replay side, where there is nothing to bound.
     * @param EffectRedactor|null $redactor Applied to every recorded `call`/`result`. Null skips
     *        redaction, for a ledger on the replay side -- a cassette's effects were already
     *        scrubbed when they were recorded, and scrubbing them again on read would only remove
     *        data replay needs.
     */
    public function __construct(
        array $effects = [],
        private readonly ?int $maxPayloadBytes = null,
        private readonly ?EffectRedactor $redactor = null,
        private readonly LedgerDirection $direction = LedgerDirection::Recording,
    ) {
        $this->effects = $effects;
    }

    /** A ledger that observes a live request, bounded and scrubbed. */
    public static function forRecording(?int $maxPayloadBytes = null, ?EffectRedactor $redactor = null): self
    {
        return new self([], $maxPayloadBytes, $redactor, LedgerDirection::Recording);
    }

    /**
     * A ledger that serves a recorded request from a cassette's effects.
     *
     * Unbounded and unredacted by construction: the effects were bounded and scrubbed when they were
     * recorded, and doing either again on read would only remove data the replay needs.
     *
     * @param list<Effect> $effects
     */
    public static function forReplay(array $effects): self
    {
        return new self($effects, null, null, LedgerDirection::Replaying);
    }

    public function direction(): LedgerDirection
    {
        return $this->direction;
    }

    /**
     * Whether a collaborator looking at this ledger should answer from it rather than perform the
     * call.
     *
     * This is the question a driver decorator installed permanently on a connection has to ask
     * before it does anything: recording means execute and append, replaying means do not touch the
     * connection and serve what was recorded.
     */
    public function isReplaying(): bool
    {
        return $this->direction === LedgerDirection::Replaying;
    }

    /**
     * Appends a freshly observed effect, assigning it the next sequence
     * number. Used while recording.
     *
     * @throws \LogicException if this ledger is replaying -- appending to a cassette's effects
     *         mid-replay would mean a stub inventing history, and every caller that could do it by
     *         accident is a decorator that should have checked {@see isReplaying()} first.
     *
     * Every `call`/`result` goes through {@see EffectRedactor} first when one was supplied. That
     * placement is deliberate: the ledger is the single point every recorder in every driver
     * package already funnels through, so a recorder cannot forget to redact the way most of them
     * had -- see {@see EffectRedactor}'s own docblock for what each one was leaking.
     *
     * An effect's `result` is the largest thing a cassette carries after the request and
     * response bodies -- a cache value, a captured result set, an HTTP response body -- and
     * `replay.max_effects` bounds only how *many* effects are kept, not how large. Charging the
     * payload against {@see $maxPayloadBytes} here is what keeps a request that reads two
     * thousand cached page fragments from building a multi-gigabyte cassette in memory before
     * gzip ever sees it. Past the ceiling the `result` is replaced with a marker naming what was
     * dropped, rather than the effect being discarded: that a call happened, and with what
     * fingerprint, is the part replay needs most and the part that costs least.
     *
     * @param array<string, mixed> $call
     * @param non-negative-int|null $durationMicros
     */
    public function record(EffectKind $kind, string $fingerprint, array $call, mixed $result, ?int $durationMicros = null): Effect
    {
        if ($this->direction === LedgerDirection::Replaying) {
            throw new \LogicException(sprintf(
                'Cannot record a %s effect into a replaying ledger: a replay serves recorded effects and '
                . 'performs nothing. Check EffectLedger::isReplaying() before recording.',
                $kind->value,
            ));
        }

        if ($this->redactor !== null) {
            // Before the budget check below, so a payload is measured at the size it will
            // actually be stored at, and before anything is held on $this, so a denied value
            // never sits in the ledger unredacted even briefly.
            $call = $this->redactor->redactCall($kind, $call);
            $result = $this->redactor->redactResult($kind, $call, $result);
        }

        if ($this->maxPayloadBytes !== null) {
            $remaining = $this->maxPayloadBytes - $this->payloadBytesUsed;
            $size = self::approximateSize($result, $remaining);
            if ($size > $remaining) {
                $this->payloadTruncated = true;
                $result = ['truncated' => true, 'reason' => 'effect payload budget exhausted'];
            } else {
                $this->payloadBytesUsed += $size;
            }
        }

        $effect = new Effect(count($this->effects), $kind, $fingerprint, $call, $result, $durationMicros);
        $this->effects[] = $effect;

        return $effect;
    }

    /**
     * Whether any effect's payload was replaced with a marker because the budget ran out. The
     * cassette says so in `meta.effects_truncated`, so a reader can tell an incomplete recording
     * apart from a complete one -- otherwise replay reports the missing data as drift in the
     * application.
     */
    public function payloadTruncated(): bool
    {
        return $this->payloadTruncated;
    }

    /**
     * A rough byte size for a recorded payload, abandoned as soon as it passes `$limit`.
     *
     * Deliberately not `strlen(serialize($value))`: serializing to measure copies the whole
     * value, which is the cost this budget exists to avoid paying. Walking the structure with an
     * early exit means an oversized payload is recognised from its first few kilobytes and never
     * measured in full. The number only has to be good enough to keep the total in the right
     * order of magnitude, so a flat per-node overhead stands in for container bookkeeping.
     */
    private static function approximateSize(mixed $value, int $limit): int
    {
        if ($limit < 0) {
            return PHP_INT_MAX;
        }
        if (is_string($value)) {
            return strlen($value);
        }
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return 8;
        }
        if (!is_array($value)) {
            // An object or a resource: not measurable without walking it, and not something a
            // recorder should be putting in a cassette. Charged a nominal amount rather than
            // guessed at.
            return 64;
        }

        $size = 0;
        foreach ($value as $key => $item) {
            $size += is_string($key) ? strlen($key) + 8 : 16;
            $size += self::approximateSize($item, $limit - $size);
            if ($size > $limit) {
                return $size;
            }
        }

        return $size;
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
