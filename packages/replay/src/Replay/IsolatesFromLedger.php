<?php

declare(strict_types=1);

namespace Quiote\Replay\Replay;

use Quiote\Replay\Recording\EffectSource;

/**
 * An {@see EffectSource} whose driver answers from a replaying {@see EffectLedger} instead of
 * reaching its real connection.
 *
 * Declared as a separate interface rather than a method on `EffectSource` because it is a genuine
 * capability difference, not a configuration choice, and it follows from *where* an ORM lets an
 * observer sit:
 *
 *  - `quioteframework/replay-doctrine` records through a DBAL **driver middleware** -- a decorator
 *    wrapped around the statement, called *instead of* the real one. It can therefore skip the real
 *    execution entirely and serve the recorded rows, so it implements this.
 *  - `quioteframework/replay-eloquent` records from the `QueryExecuted` **event**, which Eloquent
 *    fires *after* the query has run and its rows have already gone back to the caller.
 *  - `quioteframework/replay-cycle` records through Cycle's PSR-3 **logger**, likewise after the
 *    fact.
 *  - `quioteframework/replay-propulsion` records through a query **observer**, which brackets a real
 *    execution rather than replacing it.
 *
 * Those last three can only watch. There is no point at which they could return a recorded result,
 * so an isolated replay through them would silently read from -- and write to -- the real database
 * while believing it was isolated. {@see IsolatedReplay} refuses to run rather than do that, and
 * names the package, which is why this interface exists to be checked for rather than assumed.
 */
interface IsolatesFromLedger extends EffectSource
{
}
