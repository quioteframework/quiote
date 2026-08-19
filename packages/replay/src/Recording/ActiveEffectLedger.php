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
 * No correlation id is needed, unlike {@see EffectLedgerRegistry}: exactly
 * one request is ever active on a given PHP thread at a time (`activate()`
 * runs before `$handler->handle()` and `deactivate()` right after it
 * returns, both synchronous within {@see RecorderMiddleware::process()}),
 * and PHP statics are thread-local under ZTS, so concurrent worker threads
 * never share this value.
 */
final class ActiveEffectLedger
{
    private static ?EffectLedger $ledger = null;

    private function __construct()
    {
    }

    public static function set(?EffectLedger $ledger): void
    {
        self::$ledger = $ledger;
    }

    /** Null when no request is currently recording on this thread. */
    public static function get(): ?EffectLedger
    {
        return self::$ledger;
    }

    /** Test isolation. */
    public static function reset(): void
    {
        self::$ledger = null;
    }
}
