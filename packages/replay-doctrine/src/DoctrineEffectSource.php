<?php

declare(strict_types=1);

namespace Quiote\Replay\Adapter\Doctrine;

use Quiote\Replay\Recording\ActiveEffectLedger;
use Quiote\Replay\Recording\EffectSource;
use Quiote\Replay\Replay\EffectLedger;

/**
 * The {@see EffectSource} implementation `Quiote\Replay\Recording\RecorderMiddleware`
 * activates/deactivates around one request. Unlike
 * `quioteframework/replay-propulsion`'s {@see \Quiote\Replay\Adapter\Propulsion\PropulsionEffectSource}
 * (which routes by correlation id, because Propulsion's query observer is
 * process-scoped), {@see DoctrineRecordingMiddleware}'s decorator chain is
 * wrapped around one specific connection -- so this source only has to point
 * {@see ActiveEffectLedger} at the current request's ledger; every
 * `ReplayDoctrineDatabase`/`ReplayDoctrineDbalDatabase` connection reads it
 * from there.
 */
final class DoctrineEffectSource implements EffectSource
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
