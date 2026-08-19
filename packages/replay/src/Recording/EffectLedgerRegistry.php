<?php

declare(strict_types=1);

namespace Quiote\Replay\Recording;

use Quiote\Replay\Replay\EffectLedger;

/**
 * Routes a driver observation back to the request it belongs to, by
 * correlation id. The plumbing an {@see EffectSource} implementation needs
 * when its underlying instrumentation seam is process-scoped rather than
 * per-connection (Propulsion's `addQueryObserver()` being the motivating
 * case, in `quioteframework/replay-propulsion`) -- a single observer
 * registered once at boot needs to find *which* request's
 * {@see EffectLedger} a given correlation id belongs to.
 *
 * Safe under a shared-process worker model for the same reason a driver's
 * own correlation id is (see e.g. Propulsion's `docs/WORKER_MODE.md` R10):
 * each request uses a unique id (`Quiote\Support\CorrelationId`), so
 * concurrent requests never collide on the same key even though the
 * underlying array is one shared structure.
 */
final class EffectLedgerRegistry
{
    /** @var array<string, EffectLedger> */
    private static array $ledgers = [];

    private function __construct()
    {
    }

    public static function register(string $correlationId, EffectLedger $ledger): void
    {
        self::$ledgers[$correlationId] = $ledger;
    }

    public static function forget(string $correlationId): void
    {
        unset(self::$ledgers[$correlationId]);
    }

    /** Null when no request is recording under this correlation id (including when it's null itself). */
    public static function get(?string $correlationId): ?EffectLedger
    {
        return $correlationId !== null ? (self::$ledgers[$correlationId] ?? null) : null;
    }

    /** Test isolation. */
    public static function reset(): void
    {
        self::$ledgers = [];
    }
}
