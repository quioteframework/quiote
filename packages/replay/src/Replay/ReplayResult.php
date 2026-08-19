<?php

declare(strict_types=1);

namespace Quiote\Replay\Replay;

use Psr\Http\Message\ResponseInterface;

/** What one call to {@see ReplayEngine::replay()} produced. */
final readonly class ReplayResult
{
    /**
     * @param EffectLedger|null $ledger The ledger an isolated replay served from, so a caller can
     *        ask what was missed, unplayed or fuzzily matched. Null for a live replay, which has no
     *        ledger: its effects went to real collaborators, where nothing could notice one missing.
     */
    public function __construct(
        public ResponseInterface $response,
        public DriftReport $drift,
        public ?EffectLedger $ledger = null,
    ) {
    }
}
