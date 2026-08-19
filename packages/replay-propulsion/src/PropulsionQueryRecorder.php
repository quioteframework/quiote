<?php

declare(strict_types=1);

namespace Quiote\Replay\Adapter\Propulsion;

use Propulsion\Observability\BoundParameter;
use Propulsion\Observability\QueryExecution;
use Propulsion\Observability\RowCapturingQueryObserver;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Db\RecordingPdoStatement;
use Quiote\Replay\Recording\EffectLedgerRegistry;
use Quiote\Replay\Recording\Redactor;
use Quiote\Replay\Replay\EffectLedger;

/**
 * Records every Propulsion query into whichever request's {@see EffectLedger}
 * it belongs to, via Propulsion's own observer seam ({@see RowCapturingQueryObserver}).
 *
 * **Registered exactly once, at boot**, by {@see ReplayPropulsionPlugin}.
 * `Propulsion::addQueryObserver()`/`removeQueryObserver()` are process-scoped,
 * not request-scoped: a threaded worker (FrankenPHP `worker <n> > 1`) shares
 * `Propulsion::$session` and everything it reaches across every thread with
 * no per-thread isolation (`docs/WORKER_MODE.md` R10 in the Propulsion repo),
 * so adding/removing an observer per request would read and write
 * process-wide state from what should be request-scoped code. Instead, this
 * class routes each `QueryExecution` to the right ledger via
 * `$execution->correlationId` and {@see EffectLedgerRegistry}, which
 * {@see PropulsionEffectSource} populates for the duration of one request
 * via `Quiote\Replay\Recording\RecorderMiddleware`'s generic
 * `EffectSource` seam.
 *
 * **Bound parameters and captured rows are redacted here**, at the moment
 * they're about to enter the ledger -- never deferred to serialization, the
 * same rule {@see Redactor}'s own docblock states for every other capture
 * path. Both now carry real column names (`BoundParameter::$column`,
 * `QueryExecution::getColumnNames()`), so both go through the same
 * `replay.redact.params` denylist everything else uses. A bound value with
 * no known column (a raw/manual PDO bind, outside the ORM's own SQL-building
 * path) passes through unredacted -- there is nothing to check it against.
 *
 * **Recorded exactly once per query**: `queryFinished()` records immediately
 * whenever the statement has no result set to report rows for -- `PDO::exec()`
 * (`SOURCE_EXEC`), a `PDOStatement::execute()` that changed rows rather than
 * selecting them (signalled by `QueryExecution::getRowCount()` being non-null
 * -- `PropulsionStatement::execute()` only reports a row count for exactly
 * that case), or a query that was never asked to capture rows at all. A
 * genuine result-set-bearing statement (`getRowCount() === null`) that
 * requested capture is recorded only in {@see rowsCaptured()} instead, which
 * `docs/OBSERVABILITY.md` documents as guaranteed to eventually fire once
 * requested (cursor exhausted, an explicit `closeCursor()`, a re-`execute()`,
 * or the statement's own destructor as a last resort). Relying on
 * `wantsRowCapture()` alone here would be wrong: it says a capture was
 * *asked for*, not that the statement has anything to capture, and an
 * INSERT/UPDATE/DELETE that nothing ever calls `fetch()`/`closeCursor()` on
 * would otherwise depend on the statement variable eventually being
 * destructed to be recorded at all.
 *
 * **A query that threw is not recorded** -- `queryFinished()` still runs for
 * a failed statement, but a failed statement never reaches `rowsCaptured()`
 * either (there is no result set to exhaust), so this is the one place that
 * rule needs to be enforced.
 *
 * **Row capture is only requested for `SOURCE_STATEMENT`, not `SOURCE_QUERY`**
 * (a raw `$connection->query()` call) -- verified (not assumed): Propulsion's
 * own `PropulsionPDO::query()` builds and notifies a `QueryExecution` but
 * never attaches it to the returned statement's `$currentExecution`, so
 * `rowsCaptured()` can never fire for one no matter what's requested here.
 * The query is still recorded (immediately, in `queryFinished()`, with no
 * rows) rather than silently never appearing in the ledger. This doesn't
 * affect the ORM's own generated code, which always binds through
 * `prepare()`/`bindValue()`/`execute()` (`SOURCE_STATEMENT`) regardless --
 * only raw application code calling `query()` directly loses row capture,
 * and only until that Propulsion gap is fixed.
 */
final class PropulsionQueryRecorder implements RowCapturingQueryObserver
{
    private const MAX_CAPTURED_ROWS = 100;

    /**
     * @param (\Closure(): Redactor)|null $redactorFactory Resolves the {@see Redactor} per query.
     *        A factory rather than an instance because this recorder is constructed once, at
     *        plugin registration, and `Redactor::fromConfig()` reads `replay.redact.*` at the
     *        moment it is called: freezing one in at boot meant an application's own denylist,
     *        not necessarily loaded that early, was silently replaced by the hardcoded defaults
     *        with no error to notice. `RecorderMiddleware` builds a fresh one per request for the
     *        same reason. Null uses `Redactor::fromConfig()`.
     */
    public function __construct(private readonly ?\Closure $redactorFactory = null)
    {
    }

    private function redactor(): Redactor
    {
        return $this->redactorFactory !== null ? ($this->redactorFactory)() : Redactor::fromConfig();
    }

    public function queryStarted(QueryExecution $execution): void
    {
        if (EffectLedgerRegistry::get($execution->correlationId) === null) {
            return;
        }
        // SOURCE_STATEMENT only, not SOURCE_QUERY -- verified (not assumed): PropulsionPDO::query()
        // builds and notifies a QueryExecution but never attaches it to the returned statement's
        // $currentExecution, so a query()-sourced statement's rowsCaptured() can never fire no
        // matter what's requested here. Requesting capture for it anyway would make queryFinished()
        // wait forever for an event that never comes, and the query would silently never be
        // recorded at all -- worse than recording it without rows. The ORM's own generated code
        // (BasePeer::doSelect() et al.) always binds through prepare()/bindValue()/execute()
        // (SOURCE_STATEMENT) regardless, so this doesn't affect the ORM's normal query path; only
        // raw application code calling the connection's query() method directly loses row capture,
        // and even then only until Propulsion's gap is fixed.
        if ($execution->source === QueryExecution::SOURCE_STATEMENT) {
            $execution->requestRowCapture(self::MAX_CAPTURED_ROWS);
        }
    }

    public function queryFinished(QueryExecution $execution): void
    {
        if ($execution->isFailed()) {
            return;
        }
        // A statement with no result set (INSERT/UPDATE/DELETE via PDOStatement::execute(), not
        // just PDO::exec()) has nothing for rowsCaptured() to ever fire for: nothing calls
        // fetch()/closeCursor() on a statement with no rows to fetch, and this must not depend
        // on the statement variable eventually going out of scope to be recorded at all.
        // PropulsionStatement::execute() itself only reports a non-null row count for exactly
        // this case (its own columnCount() === 0 check), so that's the signal to use, not
        // wantsRowCapture() alone -- which only says a capture was asked for, not that the
        // statement has anything to capture.
        if ($execution->getRowCount() !== null || !$execution->wantsRowCapture()) {
            $this->record($execution, rows: null, columns: null, truncated: false);
        }
    }

    public function rowsCaptured(QueryExecution $execution): void
    {
        $this->record($execution, $execution->getCapturedRows(), $execution->getColumnNames(), $execution->isRowsTruncated());
    }

    /**
     * @param array<int, mixed>|null $rows
     * @param array<int, string>|null $columns
     */
    private function record(QueryExecution $execution, ?array $rows, ?array $columns, bool $truncated): void
    {
        $ledger = EffectLedgerRegistry::get($execution->correlationId);
        if ($ledger === null) {
            return;
        }

        $durationSeconds = $execution->getDurationSeconds();

        $redactor = $this->redactor();
        $result = ['row_count' => $execution->getRowCount()];
        if ($rows !== null) {
            $result['rows'] = array_map(static fn(mixed $row): mixed => is_array($row) ? $redactor->redactRowValues($columns, $row) : $row, $rows);
            $result['columns'] = $columns;
            $result['truncated'] = $truncated;
        }

        $ledger->record(
            EffectKind::Db,
            RecordingPdoStatement::fingerprintOf($execution->sql),
            [
                'sql' => $execution->sql,
                'source' => $execution->source,
                'bound_params' => self::redactBoundParams($redactor, $execution->boundParams),
            ],
            $result,
            $durationSeconds === null ? null : max(0, (int)round($durationSeconds * 1_000_000)),
        );
    }

    /**
     * @param array<int|string, BoundParameter> $boundParams
     * @return array<int|string, array{value: mixed, table: ?string, column: ?string}>
     */
    private static function redactBoundParams(Redactor $redactor, array $boundParams): array
    {
        $result = [];
        foreach ($boundParams as $placeholder => $param) {
            $result[$placeholder] = [
                'value' => $redactor->redactColumnValue($param->column, $param->value),
                'table' => $param->table,
                'column' => $param->column,
            ];
        }

        return $result;
    }
}
