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
 * Deliberately unsupported in this iteration: `fetchColumn()`,
 * `bindColumn()`, `getColumnMeta()`, LOB streaming, and any fetch mode beyond
 * ASSOC/NUM/OBJ/BOTH/default. Each throws a clear `\RuntimeException` rather
 * than falling through to a parent that no longer has a live cursor to
 * answer from.
 */
class RecordingPdoStatement extends PDOStatement
{
    use PdoRowFormatting;

    /** @var list<array<string, mixed>>|null Snapshot of every row, once execute() has run and produced a result set. */
    private ?array $rows = null;

    /** Cursor position into {@see $rows} for sequential fetch(). */
    private int $cursor = 0;

    protected function __construct(
        private readonly EffectLedger $ledger,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
    }

    /** @param array<int|string, mixed>|null $params */
    #[\Override]
    public function execute(?array $params = null): bool
    {
        $start = $this->clock->monotonic();
        $result = parent::execute($params);
        $durationMicros = RecordingPdo::durationMicros($this->clock, $start);

        if (!$result) {
            return false;
        }

        $this->rows = null;
        $this->cursor = 0;

        if ($this->columnCount() > 0) {
            $this->rows = array_values(parent::fetchAll(PDO::FETCH_ASSOC));
            $this->ledger->record(
                EffectKind::Db,
                self::fingerprintOf($this->queryString),
                ['sql' => $this->queryString, 'params' => $params ?? []],
                $this->rows,
                $durationMicros,
            );
        } else {
            $affected = parent::rowCount();
            $this->ledger->record(
                EffectKind::Db,
                self::fingerprintOf($this->queryString),
                ['sql' => $this->queryString, 'params' => $params ?? []],
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

        return $this->formatRow($row, $mode);
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

        return array_map(fn(array $row): mixed => $this->formatRow($row, $mode), $remaining);
    }

    #[\Override]
    public function rowCount(): int
    {
        return $this->rows !== null ? count($this->rows) : parent::rowCount();
    }

    #[\Override]
    public function fetchColumn(int $column = 0): mixed
    {
        throw new \RuntimeException('RecordingPdoStatement does not support fetchColumn(); use fetch() and read the column by name.');
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

    /** Trim + collapse internal whitespace runs; deliberately not full SQL normalization. */
    public static function fingerprintOf(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }
}
