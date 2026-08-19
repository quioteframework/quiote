<?php

declare(strict_types=1);

namespace Quiote\Replay\Adapter\Cycle;

use Quiote\Replay\Recording\ActiveEffectLedger;
use Quiote\Replay\Recording\EffectSource;
use Quiote\Replay\Replay\EffectLedger;

/**
 * The {@see EffectSource} implementation `Quiote\Replay\Recording\RecorderMiddleware`
 * activates/deactivates around one request. {@see CycleRecordingLogger} is
 * installed once, via `Cycle\Database\DatabaseManager::setLogger()`, so this
 * source only has to point {@see ActiveEffectLedger} at the current
 * request's ledger -- every `ReplayCycleDatabase` connection reads it from
 * there.
 */
final class CycleEffectSource implements EffectSource
{
    public function activate(string $correlationId, EffectLedger $ledger): void
    {
        ActiveEffectLedger::set($ledger);
    }

    public function deactivate(string $correlationId): void
    {
        ActiveEffectLedger::set(null);
    }
}
