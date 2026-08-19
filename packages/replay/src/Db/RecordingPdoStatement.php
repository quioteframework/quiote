<?php

declare(strict_types=1);

namespace Quiote\Replay\Db;

use PDO;
use PDOStatement;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Replay\EffectLedger;
use Quiote\Support\Clock\ClockInterface;
use Quiote\Support\Clock\SystemClock;

/**
 * The statement class {@see RecordingPdo} installs via
 * `PDO::ATTR_STATEMENT_CLASS`, so every statement it prepares records one
 * {@see EffectKind::Db} effect per {@see execute()} call.
 *
 * A PDO cursor is forward-only on most drivers, so recording the result set
 * (`parent::fetchAll()`, once, right after a real `execute()`) would consume
 * it out from under the real caller's own fetch loop. This class avoids that
 * by snapshotting the rows itself and serving every subsequent
 * {@see fetch()}/{@see fetchAll()}/{@see rowCount()} call from that snapshot
 * rather than the parent -- functionally transparent for the common
 * ASSOC/NUM/OBJ/BOTH/default fetch modes.
 *
 * The snapshot is taken in `PDO::FETCH_NUM` alongside the column names from
 * `getColumnMeta()`, not in `PDO::FETCH_ASSOC`. An associative snapshot
 * collapses duplicate column names -- `SELECT a.id, b.id FROM a JOIN b` keeps
 * one `id` -- and every positional mode is then rebuilt from that collapsed
 * row, so `FETCH_NUM` and `FETCH_BOTH` returned the wrong column count and the
 * wrong values. Positional plus names loses nothing and derives the
 * associative view from it.
 *
 * It is also bounded: `$maxSnapshotRows` caps how many rows are held, so a
 * query returning a million rows does not become a million rows in memory
 * twice over (once as the snapshot, once in the ledger). Past the cap the
 * effect says `rows_truncated`, and the caller still receives every row that
 * was captured -- see {@see execute()}.
 *
 * Deliberately unsupported in this iteration: `bindColumn()`,
 * `getColumnMeta()`, LOB streaming, and any fetch mode beyond
 * ASSOC/NUM/OBJ/BOTH/default. Each throws a clear `\RuntimeException` rather
 * than falling through to a parent that no longer has a live cursor to
 * answer from.
 */
class RecordingPdoStatement extends PDOStatement
{
    use PdoRowFormatting;

    /**
     * How many rows one statement's snapshot holds before it stops capturing. A cap this class
     * enforces itself rather than leaving to `replay.max_effects` (which counts effects, not
     * rows) or to the ledger's byte budget (which would drop the whole payload rather than
     * keeping a usable prefix).
     */
    public const DEFAULT_MAX_SNAPSHOT_ROWS = 1000;

    /** @var list<array<array-key, mixed>>|null Snapshot of the result set, once execute() has produced one. */
    private ?array $rows = null;

    /** @var list<string>|null Column names in positional order, for rebuilding the associative view. */
    private ?array $columnNames = null;

    /** Cursor position into {@see $rows} for sequential fetch(). */
    private int $cursor = 0;

    /** @var array<int|string, mixed> Values bound via bindValue()/bindParam(), for the effect's call record. */
    private array $boundParams = [];

    protected function __construct(
        private readonly EffectLedger $ledger,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly int $maxSnapshotRows = self::DEFAULT_MAX_SNAPSHOT_ROWS,
    ) {
    }

    /**
     * Records the bound value before delegating, so the effect's `call` carries what the
     * statement was actually executed with.
     *
     * Without this, only the array passed to `execute()` was captured -- and the common prepared
     * statement path binds through here instead, so the recorded `params` was empty for most real
     * queries and {@see fingerprintFor()} had nothing to distinguish two executions of the same
     * SQL with different values.
     */
    #[\Override]
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->boundParams[$param] = $value;

        return parent::bindValue($param, $value, $type);
    }

    /**
     * Records the bound variable's value at bind time and delegates by reference.
     *
     * A `bindParam()` binding is read by the driver at `execute()` time rather than now, so a
     * caller that changes the variable in between makes this snapshot stale. `execute()` therefore
     * re-reads nothing and the recorded value is the one bound here, which is the best a decorator
     * at this layer can honestly claim -- stated rather than left to look exact.
     */
    #[\Override]
    public function bindParam(string|int $param, mixed &$var, int $type = PDO::PARAM_STR, int $maxLength = 0, mixed $driverOptions = null): bool
    {
        $this->boundParams[$param] = $var;

        return parent::bindParam($param, $var, $type, $maxLength, $driverOptions);
    }

    /** @param array<int|string, mixed>|null $params */
    #[\Override]
    public function execute(?array $params = null): bool
    {
        $start = $this->clock->monotonic();
        $result = parent::execute($params);
        $durationMicros = RecordingPdo::durationMicros($this->clock, $start);

        // Reset before the failure return, not after it: leaving the previous execution's
        // snapshot in place meant a re-executed statement that then failed kept serving the
        // earlier run's rows from fetch() instead of false.
        $this->rows = null;
        $this->columnNames = null;
        $this->cursor = 0;

        if (!$result) {
            return false;
        }

        // execute($params) supersedes anything bound earlier for this run; a null argument means
        // the bindValue()/bindParam() bindings are what the statement ran with.
        $effectiveParams = $params ?? $this->boundParams;

        if ($this->columnCount() > 0) {
            $this->columnNames = $this->readColumnNames();
            [$rows, $truncated] = $this->snapshotRows();
            $this->rows = $rows;
            $this->ledger->record(
                EffectKind::Db,
                self::fingerprintFor($this->queryString, $effectiveParams),
                ['sql' => $this->queryString, 'params' => $effectiveParams],
                $truncated
                    ? ['rows' => $this->associativeRows(), 'rows_truncated' => true, 'captured_row_count' => count($rows)]
                    : $this->associativeRows(),
                $durationMicros,
            );
        } else {
            $affected = parent::rowCount();
            $this->ledger->record(
                EffectKind::Db,
                self::fingerprintFor($this->queryString, $effectiveParams),
                ['sql' => $this->queryString, 'params' => $effectiveParams],
                $affected,
                $durationMicros,
            );
        }

        return true;
    }

    #[\Override]
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if ($this->rows === null) {
            return false;
        }
        if ($cursorOrientation !== PDO::FETCH_ORI_NEXT) {
            throw new \RuntimeException('RecordingPdoStatement only supports the default forward-only fetch cursor orientation.');
        }
        if (!isset($this->rows[$this->cursor])) {
            return false;
        }

        $row = $this->rows[$this->cursor];
        $this->cursor++;

        return $this->formatPositionalRow($row, $this->columnNames, $mode);
    }

    /** @return list<mixed> */
    #[\Override]
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if ($this->rows === null) {
            return [];
        }

        $remaining = array_slice($this->rows, $this->cursor);
        $this->cursor = count($this->rows);

        return array_map(fn(array $row): mixed => $this->formatPositionalRow($row, $this->columnNames, $mode), $remaining);
    }

    #[\Override]
    public function rowCount(): int
    {
        return $this->rows !== null ? count($this->rows) : parent::rowCount();
    }

    /**
     * Answered from the snapshot rather than refused.
     *
     * Previously this threw, which made the decorator unusable for the single most common way to
     * read a scalar aggregate (`SELECT COUNT(*)` then `fetchColumn()`) -- installing the recorder
     * broke working code. The snapshot has the positional values, so the column index it takes is
     * exactly what it can answer with.
     */
    #[\Override]
    public function fetchColumn(int $column = 0): mixed
    {
        if ($this->rows === null || !isset($this->rows[$this->cursor])) {
            return false;
        }

        $row = array_values($this->rows[$this->cursor]);
        $this->cursor++;

        return $row[$column] ?? false;
    }

    #[\Override]
    public function bindColumn(string|int $column, mixed &$var, int $type = PDO::PARAM_STR, int $maxLength = 0, mixed $driverOptions = null): bool
    {
        throw new \RuntimeException('RecordingPdoStatement does not support bindColumn().');
    }

    #[\Override]
    public function getColumnMeta(int $column): array|false
    {
        throw new \RuntimeException('RecordingPdoStatement does not support getColumnMeta().');
    }

    /**
     * Normalized SQL plus a digest of the bound parameters.
     *
     * SQL alone cannot distinguish two executions of one prepared statement with different values,
     * which is the shape a loop over `WHERE id = ?` produces -- so every execution fingerprinted
     * identically and replay could only match them by position. `Effect`'s own docblock already
     * described the fingerprint as including the parameters; this is what makes that true.
     *
     * @param array<int|string, mixed> $params
     */
    public static function fingerprintFor(string $sql, array $params = []): string
    {
        $normalized = self::normalizeSql($sql);
        if ($params === []) {
            return $normalized;
        }

        $encoded = json_encode($params, JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $normalized . ' #' . substr(hash('sha256', $encoded === false ? serialize($params) : $encoded), 0, 16);
    }

    /** Trim + collapse internal whitespace runs; deliberately not full SQL normalization. */
    public static function fingerprintOf(string $sql): string
    {
        return self::normalizeSql($sql);
    }

    private static function normalizeSql(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }

    /**
     * Snapshots up to `$maxSnapshotRows` rows positionally.
     *
     * @return array{0: list<array<array-key, mixed>>, 1: bool} rows, and whether the cap stopped it short
     */
    private function snapshotRows(): array
    {
        $rows = [];
        while (count($rows) < $this->maxSnapshotRows) {
            $row = parent::fetch(PDO::FETCH_NUM);
            if (!is_array($row)) {
                return [$rows, false];
            }
            $rows[] = $row;
        }

        // One more read decides whether the cap actually cut anything off, rather than reporting
        // truncation for a result set that happened to be exactly $maxSnapshotRows long.
        return [$rows, is_array(parent::fetch(PDO::FETCH_NUM))];
    }

    /** @return list<string> */
    private function readColumnNames(): array
    {
        $names = [];
        $count = $this->columnCount();
        for ($index = 0; $index < $count; $index++) {
            $meta = parent::getColumnMeta($index);
            // A driver that cannot describe a column (getColumnMeta() is optional in PDO) leaves
            // the positional index as the name, so the associative view still has a usable key.
            $names[] = is_array($meta) ? $meta['name'] : (string)$index;
        }

        return $names;
    }

    /**
     * Zips a positional row against the column names, so the associative view is derived from the
     * snapshot rather than the snapshot being taken associatively.
     *
     * @param array<array-key, mixed> $row
     * @return array<array-key, mixed>
     */
    private function named(array $row): array
    {
        if ($this->columnNames === null) {
            return $row;
        }

        $named = [];
        foreach (array_values($row) as $index => $value) {
            $named[$this->columnNames[$index] ?? $index] = $value;
        }

        return $named;
    }

    /**
     * The whole snapshot as associative rows, for the ledger -- what a replay stub reads back.
     *
     * @return list<array<array-key, mixed>>
     */
    private function associativeRows(): array
    {
        return array_map($this->named(...), $this->rows ?? []);
    }
}
