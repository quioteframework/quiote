<?php

declare(strict_types=1);

namespace Quiote\Replay\Replay;

use Quiote\Replay\Recording\EffectSource;

/**
 * An {@see EffectSource} whose driver answers from a replaying {@see EffectLedger} instead of
 * reaching its real connection.
 *
 * Declared as a separate interface rather than a method on `EffectSource` because it is a genuine
 * capability difference, not a configuration choice. What decides it is not how a package *records*
 * but whether it has any seam at all through which a recorded result can be returned:
 *
 *  - `quioteframework/replay-doctrine` records through a DBAL **driver middleware** -- a decorator
 *    wrapped around the statement, called *instead of* the real one -- so it can skip the real
 *    execution and serve the recorded rows through the same object.
 *  - `quioteframework/replay-propulsion` records through a query **observer**, which brackets a real
 *    execution and cannot answer one; it isolates by **substituting the connection** instead, which
 *    Propulsion supports directly. Different mechanism, same capability.
 *  - `quioteframework/replay-eloquent` records from the `QueryExecuted` **event**, which Eloquent
 *    fires *after* the query has run and its rows have already gone back to the caller.
 *  - `quioteframework/replay-cycle` records through Cycle's PSR-3 **logger**, likewise after the
 *    fact.
 *
 * Those last two can only watch, and neither Eloquent nor Cycle offers a connection-level
 * substitution to fall back on. There is no point at which they could return a recorded result, so
 * an isolated replay through them would silently read from -- and write to -- the real database while
 * believing it was isolated. {@see IsolatedReplay} refuses to run rather than do that, and names the
 * package, which is why this interface exists to be checked for rather than assumed.
 *
 * The two hooks exist because *how* a driver comes to serve from the ledger differs by driver, and
 * only the driver's own package knows. Doctrine needs nothing done -- its decorator is already
 * installed on the connection and reads {@see \Quiote\Replay\Recording\ActiveEffectLedger} on every
 * statement. Propulsion has to install a stub connection per datasource and discard it afterwards.
 * Both are "serve from the ledger"; neither generalises to the other, so each states its own.
 */
interface IsolatesFromLedger extends EffectSource
{
    /**
     * Called once, before the replayed request is dispatched, to make this driver answer from
     * $ledger.
     *
     * Distinct from {@see EffectSource::activate()}, which is the *recording* lifecycle: that one
     * tells a process-wide observer which request's ledger to append to, while this one has to make
     * the driver stop performing queries altogether.
     */
    public function beginIsolation(EffectLedger $ledger): void;

    /**
     * Called once, as soon as the dispatch returns or throws, to undo {@see beginIsolation()}.
     *
     * Must be safe to call without a matching `beginIsolation()`, and must not throw: it runs in a
     * `finally`, where a throw would replace whatever the replay itself was reporting -- and would
     * leave a later request in the same process talking to a stub.
     */
    public function endIsolation(): void;
}
