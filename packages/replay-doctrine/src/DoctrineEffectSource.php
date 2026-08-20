<?php

declare(strict_types=1);

namespace Quiote\Replay\Adapter\Doctrine;

use Quiote\Replay\Recording\ActiveEffectLedger;
use Quiote\Replay\Replay\EffectLedger;
use Quiote\Replay\Replay\IsolatesFromLedger;

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
 *
 * Implements {@see IsolatesFromLedger} because that decorator chain is a DBAL driver middleware --
 * called *instead of* the real statement -- so it can serve a recorded result rather than only
 * observe one. Of the four ORM adapters this package family ships, it is the only one whose seam
 * allows that; see the interface's own docblock for why the others cannot.
 */
final class DoctrineEffectSource implements IsolatesFromLedger
{
    public function activate(string $correlationId, EffectLedger $ledger): void
    {
        ActiveEffectLedger::set($ledger);
    }

    public function deactivate(string $correlationId): void
    {
        ActiveEffectLedger::set(null);
    }

    /**
     * Nothing to do: {@see DoctrineRecordingMiddleware}'s decorator chain is already installed on
     * the connection and reads {@see ActiveEffectLedger} on every statement, which
     * {@see \Quiote\Replay\Replay\IsolatedReplay} has set to the replaying ledger by the time
     * this runs. `DoctrineRecordingDriver::connect()` likewise sees it and hands back a connection
     * that opens nothing.
     *
     * Empty because the work is genuinely already done, not because it is unimplemented -- see
     * `quioteframework/replay-propulsion`'s own implementation for the case where a driver has to be
     * actively substituted instead.
     */
    #[\Override]
    public function beginIsolation(EffectLedger $ledger): void
    {
    }

    #[\Override]
    public function endIsolation(): void
    {
    }
}
