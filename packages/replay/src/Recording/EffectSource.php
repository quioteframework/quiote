<?php

declare(strict_types=1);

namespace Quiote\Replay\Recording;

use Quiote\Replay\Replay\EffectLedger;

/**
 * A driver-specific package's hook into {@see RecorderMiddleware}'s
 * recording lifecycle, for an ORM/driver whose own instrumentation seam is
 * process-scoped rather than per-connection -- Propulsion's
 * `addQueryObserver()` being the motivating case (see
 * `quioteframework/replay-propulsion`'s own `PropulsionEffectSource`): a
 * single observer is registered once at boot, and needs telling, for the
 * duration of one request, which correlation id's queries belong to which
 * {@see EffectLedger}.
 *
 * A driver whose recorder is instead a per-request decorator constructed
 * around a specific connection (the PDO/Doctrine/Eloquent/Cycle shape) has
 * no need of this seam at all -- it just takes an `EffectLedger` directly in
 * its constructor, wherever that connection gets built.
 *
 * `packages/replay` itself ships no implementation of this interface and
 * has no compile-time dependency on any ORM; a driver package registers one
 * via {@see EffectSourceRegistry::register()} from its own plugin.
 */
interface EffectSource
{
    /** Called once, before `$handler->handle()`, for every request {@see RecorderMiddleware} buffers. */
    public function activate(string $correlationId, EffectLedger $ledger): void;

    /** Called once, as soon as `$handler->handle()` returns or throws. */
    public function deactivate(string $correlationId): void;
}
