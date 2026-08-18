<?php

declare(strict_types=1);

namespace Quiote\Replay\Db;

use Propulsion\Observability\QueryExecution;
use Propulsion\Observability\QueryObserver;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Replay\EffectLedger;

/**
 * Records every Propulsion query into an {@see EffectLedger} via Propulsion's
 * own observer seam ({@see QueryObserver}), registered process-wide with
 * `Propulsion::addQueryObserver()`. Unlike {@see RecordingPdo}/
 * {@see RecordingPdoStatement}, this needs no decoration: Propulsion already
 * notifies an observer around every statement it runs.
 *
 * **Duration** is read from {@see QueryExecution::getDurationSeconds()}
 * rather than measured here: `queryStarted()` and `queryFinished()` are
 * called with the *same* `QueryExecution` instance (per that class's own
 * docblock), and it already timestamps itself internally via `hrtime(true)`
 * around the call. Calling `ClockInterface::monotonic()` (or `hrtime()`)
 * again here would just be a second, redundant measurement of the same
 * interval -- reading the one Propulsion already took is both simpler and
 * more accurate, since it is not skewed by observer dispatch overhead.
 *
 * **No bound parameters or row data are available here**, unlike the PDO
 * decorator: `QueryExecution` exposes only the statement text, its source
 * (`SOURCE_STATEMENT`/`SOURCE_EXEC`/`SOURCE_QUERY`) and, where the driver
 * reports it, a row count -- `getRowCount()` is documented as null for a
 * SELECT on most platforms, matching `\PDOStatement::rowCount()`'s own
 * unreliability there. So `Effect::$result` here is `int|null`, not the
 * materialized row set `RecordingPdoStatement` captures.
 *
 * **A query that threw is not recorded.** `QueryObservers::finish()` still
 * calls `queryFinished()` for a failed statement (`$execution->isFailed()`
 * is true and the real exception is rethrown to the caller regardless of
 * what an observer does), so this class checks that flag and skips
 * recording rather than ledger-ing a call that never produced a usable
 * result -- consistent with the PDO recorder's own rule.
 *
 * **This class must not throw.** `QueryObservers::safely()` already catches
 * and logs anything an observer throws (so a bug here cannot break the query
 * it is observing), but `queryFinished()` is written defensively anyway
 * rather than relying on that backstop.
 */
final class PropulsionQueryRecorder implements QueryObserver
{
    public function __construct(private readonly EffectLedger $ledger)
    {
    }

    /** Nothing to stash: see the class docblock on why duration is read from $execution itself. */
    public function queryStarted(QueryExecution $execution): void
    {
    }

    public function queryFinished(QueryExecution $execution): void
    {
        if ($execution->isFailed()) {
            return;
        }

        $durationSeconds = $execution->getDurationSeconds();

        $this->ledger->record(
            EffectKind::Db,
            RecordingPdoStatement::fingerprintOf($execution->sql),
            ['sql' => $execution->sql, 'source' => $execution->source],
            $execution->getRowCount(),
            $durationSeconds === null ? null : max(0, (int) round($durationSeconds * 1_000_000)),
        );
    }
}
