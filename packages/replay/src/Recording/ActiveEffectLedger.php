<?php

declare(strict_types=1);

namespace Quiote\Replay\Recording;

use Quiote\Replay\Replay\EffectLedger;

/**
 * The single currently-active {@see EffectLedger}, for a driver whose
 * recorder is a decorator wrapped once around a specific connection (the
 * Doctrine/Eloquent/Cycle shape) rather than a process-scoped observer
 * (Propulsion's shape, which needs {@see EffectLedgerRegistry}'s
 * correlation-id map instead -- see that class's own docblock).
 *
 * A per-connection decorator is installed exactly once, when
 * `DatabaseManager::recycleConnections()` first builds the connection --
 * and, per that method's own docblock, the connection is then recycled
 * (ping()'d), not rebuilt, for the rest of the worker's lifetime. A ledger
 * fixed into the decorator's constructor at that first connect() would
 * therefore silently keep recording every later request's queries into the
 * first request's already-finished ledger. Reading the ledger dynamically
 * here instead -- {@see EffectSource::activate()}/`deactivate()} call
 * {@see set()} once per request -- makes the decorator correct for the
 * connection's entire lifetime, not just its first use.
 *
 * No correlation id is needed, unlike {@see EffectLedgerRegistry}: PHP statics are thread-local
 * under ZTS, so concurrent worker threads never share this value.
 *
 * A stack rather than a single slot, though, because dispatch does re-enter. `activate()` runs
 * before `$handler->handle()` and `deactivate()` right after it returns, which makes one request
 * per thread true only as long as nothing dispatches a request from inside one -- and this package
 * itself does: `ReplayEngine` and `ReplayTestCase` both go through
 * `Context::getRequestHandler()->handle()`, potentially from inside a request that is recording,
 * as would any internal forward or sub-request. With a single slot the inner `deactivate()` was an
 * unconditional clear, so the outer request's remaining queries went unrecorded with nothing to
 * say so. Restoring the previous value turns the invariant from an assertion in this docblock into
 * a property of the code.
 */
final class ActiveEffectLedger
{
    /**
     * Innermost-last, so {@see get()} answers with the request currently executing.
     *
     * @var list<EffectLedger>
     */
    private static array $stack = [];

    private function __construct()
    {
    }

    /**
     * Pushes a ledger, or pops back to the enclosing one when given null.
     *
     * The null form is what {@see EffectSource::deactivate()} calls, so a nested request restores
     * its parent's ledger rather than clearing recording for the rest of that parent's work.
     */
    public static function set(?EffectLedger $ledger): void
    {
        if ($ledger === null) {
            array_pop(self::$stack);

            return;
        }
        self::$stack[] = $ledger;
    }

    /** Null when no request is currently recording on this thread. */
    public static function get(): ?EffectLedger
    {
        return self::$stack === [] ? null : self::$stack[count(self::$stack) - 1];
    }

    /** How many requests are recording on this thread, outermost included. Nesting depth. */
    public static function depth(): int
    {
        return count(self::$stack);
    }

    /** Test isolation. */
    public static function reset(): void
    {
        self::$stack = [];
    }
}
