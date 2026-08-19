<?php

declare(strict_types=1);

namespace Quiote\Replay\Replay;

use Quiote\Replay\Cassette\Effect;
use Quiote\Replay\Cassette\EffectKind;

/**
 * The fingerprint-then-sequence matching algorithm: a replayed call is matched against the
 * first not-yet-consumed effect of the same {@see EffectKind} whose fingerprint is identical,
 * so two identical queries recorded back to back are still matched in the order they happened.
 * Only when no fingerprint matches does it fall back to the next not-yet-consumed effect of
 * that kind regardless of fingerprint -- and it reports that it did, via
 * {@see LedgerMatch::$fuzzy}.
 *
 * That report is what makes the fallback safe to have at all. A sequence match hands over a
 * *different* call's recorded result, and the fallback cannot tell the case it exists for -- a
 * fingerprint that could not be computed identically twice -- apart from genuine drift, where
 * the code now makes a call it did not make when recorded. Returning both as an
 * indistinguishable {@see Effect} meant drift was answered with plausible-looking data and no
 * miss recorded anywhere, which for an isolated replay is a test that passes on fabricated
 * input. {@see EffectLedger} decides what to do with a fuzzy match; the matcher only has to be
 * honest about which kind it made.
 *
 * Stateless: {@see EffectLedger} owns which effects have already been consumed and passes that
 * set in on every call.
 */
final class LedgerMatcher
{
    /**
     * @param list<Effect> $effects All recorded effects, in original order.
     * @param array<non-negative-int, true> $consumedSeqs Effect::$seq values already matched.
     */
    public static function match(array $effects, array $consumedSeqs, EffectKind $kind, string $fingerprint): ?LedgerMatch
    {
        foreach ($effects as $effect) {
            if ($effect->kind === $kind && !isset($consumedSeqs[$effect->seq]) && $effect->fingerprint === $fingerprint) {
                return LedgerMatch::exact($effect);
            }
        }

        foreach ($effects as $effect) {
            if ($effect->kind === $kind && !isset($consumedSeqs[$effect->seq])) {
                return LedgerMatch::fuzzy($effect);
            }
        }

        return null;
    }
}
