<?php

declare(strict_types=1);

namespace Quiote\Replay\Replay;

use PDO;
use PDOStatement;
use Quiote\Replay\Cassette\DbResult;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Db\PdoRowFormatting;
use Quiote\Replay\Db\RecordingPdoStatement;

/**
 * The isolated-replay counterpart to {@see RecordingPdoStatement}: never
 * touches a real connection (never calls `parent::__construct()`), and
 * answers `execute()`/`fetch()`/`fetchAll()`/`rowCount()` entirely from an
 * injected {@see EffectLedger}, matching on the same normalized-SQL
 * fingerprint the recorder used.
 *
 * A ledger miss -- the SQL has no recorded counterpart, or every recorded
 * effect for it has already been consumed -- raises rather than returning an
 * empty/invented result: inventing a result would fabricate a passing test,
 * which is exactly what isolated replay must not do.
 *
 * Deliberately unsupported, same as the recording side: `fetchColumn()`,
 * `bindColumn()`, `getColumnMeta()`, LOB streaming, and any fetch mode beyond
 * ASSOC/NUM/OBJ/BOTH/default.
 */
final class StubbedPdoStatement extends PDOStatement
{
    use PdoRowFormatting;

    /** @var list<array<array-key, mixed>>|null Set once execute() has matched a row-producing effect. */
    private ?array $rows = null;

    /** @var array<int|string, mixed> Values bound via bindValue()/bindParam(), for the fingerprint. */
    private array $boundParams = [];

    /** Set once execute() has matched a non-row-producing (exec-shaped) effect. */
    private ?int $affectedRows = null;

    private int $cursor = 0;

    private bool $executed = false;

    public function __construct(
        private readonly EffectLedger $ledger,
        private readonly string $sql,
    ) {
    }

    /**
     * Matched on the same normalized-SQL-plus-parameter-digest fingerprint the recorder writes, so
     * two executions of one prepared statement with different bound values are told apart rather
     * than matched by position.
     *
     * @param array<int|string, mixed>|null $params Superseded by anything bound through
     *        {@see bindValue()}/{@see bindParam()} only when null, matching the recording side.
     */
    #[\Override]
    public function execute(?array $params = null): bool
    {
        $effectiveParams = $params ?? $this->boundParams;
        $fingerprint = RecordingPdoStatement::fingerprintFor($this->sql, $effectiveParams);
        $effect = $this->ledger->match(EffectKind::Db, $fingerprint);
        if ($effect === null) {
            throw new \RuntimeException(sprintf(
                'StubbedPdo: no recorded database effect for SQL "%s"%s.',
                RecordingPdoStatement::fingerprintOf($this->sql),
                $effectiveParams === [] ? '' : ' with these bound parameters',
            ));
        }

        $this->executed = true;
        $this->cursor = 0;
        $this->rows = null;
        $this->affectedRows = null;

        $result = DbResult::fromResult($effect->result);
        if ($result === null) {
            throw new \RuntimeException(sprintf(
                'StubbedPdo: the recorded effect for SQL "%s" carries a %s result, which does not describe a '
                . 'database call at all. The cassette has most likely been edited.',
                RecordingPdoStatement::fingerprintOf($this->sql),
                get_debug_type($effect->result),
            ));
        }
        if ($result->rows === null && $result->affectedRows === null) {
            throw new \RuntimeException(sprintf(
                'StubbedPdo: the recorded effect for SQL "%s" captured no rows -- the recorder that wrote this '
                . 'cassette observes queries at a layer where the rows have already gone back to the caller '
                . '(quioteframework/replay-{eloquent,cycle}), so there is nothing to replay this read from. '
                . 'Re-record with a recorder that captures rows.',
                RecordingPdoStatement::fingerprintOf($this->sql),
            ));
        }

        $this->rows = $result->rows;
        $this->affectedRows = $result->affectedRows;

        return true;
    }

    /** Records a bound value, so {@see execute()} fingerprints what the recorder fingerprinted. */
    #[\Override]
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->boundParams[$param] = $value;

        return true;
    }

    #[\Override]
    public function bindParam(string|int $param, mixed &$var, int $type = PDO::PARAM_STR, int $maxLength = 0, mixed $driverOptions = null): bool
    {
        $this->boundParams[$param] = $var;

        return true;
    }

    #[\Override]
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $this->requireExecuted();
        if ($cursorOrientation !== PDO::FETCH_ORI_NEXT) {
            throw new \RuntimeException('StubbedPdoStatement only supports the default forward-only fetch cursor orientation.');
        }
        if ($this->rows === null || !isset($this->rows[$this->cursor])) {
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
        $this->requireExecuted();
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
        $this->requireExecuted();

        return $this->rows !== null ? count($this->rows) : ($this->affectedRows ?? 0);
    }

    /** Answered from the snapshot, matching {@see RecordingPdoStatement::fetchColumn()}. */
    #[\Override]
    public function fetchColumn(int $column = 0): mixed
    {
        $this->requireExecuted();
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
        throw new \RuntimeException('StubbedPdoStatement does not support bindColumn().');
    }

    #[\Override]
    public function getColumnMeta(int $column): array|false
    {
        throw new \RuntimeException('StubbedPdoStatement does not support getColumnMeta().');
    }

    private function requireExecuted(): void
    {
        if (!$this->executed) {
            throw new \RuntimeException('StubbedPdoStatement: execute() must be called before fetching.');
        }
    }
}
