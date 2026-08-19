<?php

declare(strict_types=1);

namespace Quiote\Replay\Adapter\Eloquent;

use Quiote\Replay\Recording\ActiveEffectLedger;
use Quiote\Replay\Recording\EffectSource;
use Quiote\Replay\Replay\EffectLedger;

/**
 * The {@see EffectSource} implementation `Quiote\Replay\Recording\RecorderMiddleware`
 * activates/deactivates around one request. {@see EloquentQueryRecorder} is
 * attached once, to a specific connection, so this source only has to point
 * {@see ActiveEffectLedger} at the current request's ledger -- every
 * `ReplayEloquentDatabase` connection reads it from there.
 */
final class EloquentEffectSource implements EffectSource
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
