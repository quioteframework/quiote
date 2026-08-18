<?php

declare(strict_types=1);

namespace Quiote\Replay\Replay;

use Quiote\Replay\Cassette\Effect;
use Quiote\Replay\Cassette\EffectKind;

/**
 * The fingerprint-then-sequence matching algorithm described in the
 * record/replay plan §7.2: a replayed call is matched against the first
 * not-yet-consumed effect of the same {@see EffectKind} whose fingerprint is
 * identical, and only when no fingerprint matches does it fall back to the
 * next not-yet-consumed effect of that kind regardless of fingerprint -- so
 * two identical queries recorded back to back are still matched in the order
 * they happened, and a call whose fingerprint cannot be computed exactly the
 * same way twice still has somewhere to land.
 *
 * Stateless: {@see \Quiote\Replay\Replay\EffectLedger} owns which effects have
 * already been consumed and passes that set in on every call.
 */
final class LedgerMatcher
{
    /**
     * @param list<Effect> $effects All recorded effects, in original order.
     * @param array<non-negative-int, true> $consumedSeqs Effect::$seq values already matched.
     */
    public static function match(array $effects, array $consumedSeqs, EffectKind $kind, string $fingerprint): ?Effect
    {
        foreach ($effects as $effect) {
            if ($effect->kind === $kind && !isset($consumedSeqs[$effect->seq]) && $effect->fingerprint === $fingerprint) {
                return $effect;
            }
        }

        foreach ($effects as $effect) {
            if ($effect->kind === $kind && !isset($consumedSeqs[$effect->seq])) {
                return $effect;
            }
        }

        return null;
    }
}
