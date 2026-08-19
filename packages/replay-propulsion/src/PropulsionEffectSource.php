<?php

declare(strict_types=1);

namespace Quiote\Replay\Adapter\Propulsion;

use Propulsion\Propulsion;
use Quiote\Replay\Recording\EffectLedgerRegistry;
use Quiote\Replay\Recording\EffectSource;
use Quiote\Replay\Replay\EffectLedger;

/**
 * The {@see EffectSource} implementation `Quiote\Replay\Recording\RecorderMiddleware`
 * activates/deactivates around one request, so the process-wide
 * {@see PropulsionQueryRecorder} (registered once, at boot, by
 * {@see ReplayPropulsionPlugin}) knows which correlation id's queries belong
 * to which request's {@see EffectLedger}.
 */
final class PropulsionEffectSource implements EffectSource
{
    public function activate(string $correlationId, EffectLedger $ledger): void
    {
        Propulsion::setCorrelationId($correlationId);
        EffectLedgerRegistry::register($correlationId, $ledger);
    }

    public function deactivate(string $correlationId): void
    {
        EffectLedgerRegistry::forget($correlationId);
        Propulsion::setCorrelationId(null);
    }
}
