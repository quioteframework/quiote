<?php

declare(strict_types=1);

namespace Quiote\Replay\Adapter\Eloquent;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Quiote\Replay\Cassette\DbResult;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Recording\ActiveEffectLedger;

/**
 * Records one {@see EffectKind::Db} entry per query on an Eloquent
 * (illuminate/database) connection, via `Connection::listen()` -- the
 * `Illuminate\Database\Events\QueryExecuted` event Eloquent already fires
 * after every query, rather than a PDO/DBAL-style decorator.
 *
 * This event fires *after* the query has already run and its rows already
 * returned to the caller through Eloquent's own internal fetch path, which
 * this recorder never sees -- unlike {@see RecordingPdo}/
 * {@see DoctrineRecordingMiddleware}, there is nothing here to snapshot and
 * hand back, so `Effect::$result` is always `null`. `call` carries the SQL,
 * bindings and read/write type `QueryExecuted` exposes -- no row data is
 * available at this layer, which is a real, documented limitation of
 * recording through Eloquent's event rather than decorating its PDO
 * connection, not an oversight.
 *
 * `Illuminate\Database\Connection::listen()` is a no-op when the connection
 * has no event dispatcher (`Quiote\Database\Adapter\Eloquent\EloquentDatabase::connect()`
 * never sets one), so {@see attach()} installs a
 * {@see EloquentMinimalEventDispatcher} first when the connection doesn't
 * already have one -- an application-wired dispatcher, if present, is left
 * alone and this recorder's listener is simply added alongside whatever else
 * is already listening.
 *
 * Records into {@see ActiveEffectLedger}'s current ledger rather than a
 * fixed one taken at construction: {@see attach()} runs once, at
 * `EloquentDatabase::connect()`, and that connection is then recycled (not
 * rebuilt) across every later request in a worker process -- see
 * {@see ActiveEffectLedger}'s own docblock for why a fixed ledger would be
 * wrong past the connection's first use. A query that runs with nothing
 * currently active (e.g. before any request is being recorded) is simply
 * not recorded.
 *
 * A failing query never fires `QueryExecuted` (Eloquent only dispatches it
 * after a successful run), so nothing here needs to guard against recording
 * a failed call -- that exclusion falls out of the event's own contract.
 */
final class EloquentQueryRecorder
{
    /**
     * Attaches the listener, once per connection.
     *
     * The guard matters because `connect()` can run more than once for one logical connection --
     * a reconnect after a dropped socket is the case a long-running worker exists to handle -- and
     * `Connection::listen()` appends unconditionally. Without it, every reconnect left one more
     * listener and each query was recorded that many times into the ledger.
     */
    public function attach(Connection $connection): void
    {
        if ($connection->getEventDispatcher() === null) {
            $connection->setEventDispatcher(new EloquentMinimalEventDispatcher());
        }
        if (isset(self::$attached[spl_object_id($connection)])) {
            return;
        }
        self::$attached[spl_object_id($connection)] = true;

        $connection->listen(function (QueryExecuted $event): void {
            $this->record($event);
        });
    }

    /**
     * Connections already listened to, by object id.
     *
     * Keyed by identity rather than tracked on the connection, which is a third-party class this
     * package must not decorate or annotate. `spl_object_id()` is only unique among live objects,
     * so a recycled id could in principle skip an attach for a genuinely new connection; the
     * consequence is one connection recording nothing, which is strictly safer than the duplicate
     * recording it replaces, and a connection outliving the request that built it is the whole
     * premise of the worker model this runs in.
     *
     * @var array<int, true>
     */
    private static array $attached = [];

    /** Test isolation: forgets which connections have been attached to. */
    public static function reset(): void
    {
        self::$attached = [];
    }

    private function record(QueryExecuted $event): void
    {
        $durationMicros = $event->time !== null ? max(0, (int) round($event->time * 1_000)) : null;

        ActiveEffectLedger::get()?->record(
            EffectKind::Db,
            self::fingerprintOf($event->sql),
            [
                'sql' => $event->sql,
                'bindings' => $event->bindings,
                'connection' => $event->connectionName,
                'readWriteType' => $event->readWriteType,
            ],
            // Distinct from "the query returned no rows": this recorder's seam fires after the rows
            // have already gone back to the caller, so there was never anything to capture. A
            // replay stub can then say so instead of silently replaying every read as empty.
            DbResult::unobservedRows()->toArray(),
            $durationMicros,
        );
    }

    /** Trim + collapse internal whitespace runs; deliberately not full SQL normalization. */
    public static function fingerprintOf(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }
}
