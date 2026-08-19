<?php

declare(strict_types=1);

namespace Quiote\Replay\Adapter\Eloquent;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
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
    public function attach(Connection $connection): void
    {
        if ($connection->getEventDispatcher() === null) {
            $connection->setEventDispatcher(new EloquentMinimalEventDispatcher());
        }

        $connection->listen(function (QueryExecuted $event): void {
            $this->record($event);
        });
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
            null,
            $durationMicros,
        );
    }

    /** Trim + collapse internal whitespace runs; deliberately not full SQL normalization. */
    public static function fingerprintOf(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }
}
