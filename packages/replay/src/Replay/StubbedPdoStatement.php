<?php

declare(strict_types=1);

namespace Quiote\Replay\Replay;

use PDO;
use PDOStatement;
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

        $result = self::rowsOf($effect->result);
        if ($result !== null) {
            $this->rows = $result;

            return true;
        }
        if (is_int($effect->result)) {
            $this->affectedRows = $effect->result;

            return true;
        }

        throw new \RuntimeException(sprintf(
            'StubbedPdo: the recorded effect for SQL "%s" carries a %s result, which is neither a list of '
            . 'associative rows nor an affected-row count. The cassette was written by a recorder whose '
            . 'result shape this stub cannot read, or has been edited.',
            RecordingPdoStatement::fingerprintOf($this->sql),
            get_debug_type($effect->result),
        ));
    }

    /**
     * A recorded result as a list of associative rows, or null when it is not one.
     *
     * Validated rather than asserted. This used to narrow `$effect->result` with a bare `@var`
     * after an `is_array()` test that cannot establish the shape, so a cassette whose rows are not
     * arrays of arrays -- hand-edited, or written by a recorder with a different result shape --
     * reached `formatRow()` and produced a raw `TypeError` instead of a cassette error. The
     * annotation was also what kept that invisible to static analysis: it told the analyser the
     * invariant held rather than making the code prove it.
     *
     * @return list<array<array-key, mixed>>|null
     */
    private static function rowsOf(mixed $result): ?array
    {
        if (!is_array($result) || !array_is_list($result)) {
            return null;
        }

        $rows = [];
        foreach ($result as $row) {
            if (!is_array($row)) {
                return null;
            }
            $rows[] = $row;
        }

        return $rows;
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
