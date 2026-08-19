<?php

declare(strict_types=1);

namespace Quiote\Replay\Replay;

use Psr\Http\Message\ResponseInterface;
use Quiote\Support\Compiler\Diagnostic;

/**
 * What one {@see IsolatedReplay::run()} produced: the response, the response diff, and what the
 * ledger was asked for.
 *
 * The ledger half is the part a live replay cannot report. In isolation every effect the code
 * performs goes through the ledger, so three questions become answerable that are otherwise
 * guesswork:
 *
 *  - {@see EffectLedger::misses()} -- calls the code made that the recording has no counterpart for.
 *    The code now does something it did not do when recorded.
 *  - {@see EffectLedger::unplayed()} -- recorded effects nothing asked for. The code no longer does
 *    something it used to.
 *  - {@see EffectLedger::fuzzyMatches()} -- calls answered from a recorded effect with a different
 *    fingerprint, which is a weaker claim than a match.
 *
 * {@see effectDiagnostics()} turns those into the same {@see Diagnostic} shape the response diff
 * uses, so a caller reports one list rather than two.
 */
final readonly class IsolatedReplayResult
{
    public function __construct(
        public ResponseInterface $response,
        public DriftReport $drift,
        public EffectLedger $ledger,
    ) {
    }

    /**
     * Effect drift as diagnostics, alongside the response diff's own.
     *
     * A miss is an error: the code reached for something the recording cannot answer, so whatever it
     * did next was built on a default rather than on what happened. An unplayed effect and a fuzzy
     * match are warnings -- both mean the run diverged, but the response is still the code's own
     * answer rather than a fabricated one.
     *
     * @return list<Diagnostic>
     */
    public function effectDiagnostics(string $cassetteId): array
    {
        $diagnostics = [];

        foreach ($this->ledger->misses() as $miss) {
            $diagnostics[] = new Diagnostic(
                Diagnostic::SEVERITY_ERROR,
                'REPLAY_EFFECT_MISS',
                sprintf(
                    'The replay asked for a %s effect the cassette has no counterpart for: %s',
                    $miss['kind']->value,
                    $miss['fingerprint'],
                ),
                $cassetteId,
            );
        }

        foreach ($this->ledger->fuzzyMatches() as $fuzzy) {
            $diagnostics[] = new Diagnostic(
                Diagnostic::SEVERITY_WARNING,
                'REPLAY_EFFECT_FUZZY',
                sprintf(
                    'A %s effect was answered from a recorded effect with a different fingerprint: asked for '
                    . '%s, answered from %s.',
                    $fuzzy['kind']->value,
                    $fuzzy['fingerprint'],
                    $fuzzy['matched'],
                ),
                $cassetteId,
            );
        }

        foreach ($this->ledger->unplayed() as $effect) {
            $diagnostics[] = new Diagnostic(
                Diagnostic::SEVERITY_WARNING,
                'REPLAY_EFFECT_UNPLAYED',
                sprintf(
                    'The cassette recorded a %s effect nothing asked for during the replay: %s',
                    $effect->kind->value,
                    $effect->fingerprint,
                ),
                $cassetteId,
            );
        }

        return $diagnostics;
    }

    /** The response diff and the effect drift as one list. */
    public function allDiagnostics(string $cassetteId): DriftReport
    {
        return new DriftReport([...$this->drift->diagnostics, ...$this->effectDiagnostics($cassetteId)]);
    }
}
