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
 * which is exactly what isolated replay must not do (record/replay plan §7.1).
 *
 * Deliberately unsupported, same as the recording side: `fetchColumn()`,
 * `bindColumn()`, `getColumnMeta()`, LOB streaming, and any fetch mode beyond
 * ASSOC/NUM/OBJ/BOTH/default.
 */
final class StubbedPdoStatement extends PDOStatement
{
    use PdoRowFormatting;

    /** @var list<array<string, mixed>>|null Set once execute() has matched a row-producing effect. */
    private ?array $rows = null;

    /** Set once execute() has matched a non-row-producing (exec-shaped) effect. */
    private ?int $affectedRows = null;

    private int $cursor = 0;

    private bool $executed = false;

    public function __construct(
        private readonly EffectLedger $ledger,
        private readonly string $sql,
    ) {
    }

    /** @param array<int|string, mixed>|null $params Unused: matching is by SQL fingerprint, not bound parameter values. */
    #[\Override]
    public function execute(?array $params = null): bool
    {
        $fingerprint = RecordingPdoStatement::fingerprintOf($this->sql);
        $effect = $this->ledger->match(EffectKind::Db, $fingerprint);
        if ($effect === null) {
            throw new \RuntimeException(sprintf('StubbedPdo: no recorded database effect for SQL "%s".', $fingerprint));
        }

        $this->executed = true;
        $this->cursor = 0;
        if (is_array($effect->result)) {
            /** @var list<array<string, mixed>> $rows */
            $rows = $effect->result;
            $this->rows = $rows;
            $this->affectedRows = null;
        } else {
            $this->rows = null;
            $this->affectedRows = is_int($effect->result) ? $effect->result : 0;
        }

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

    #[\Override]
    public function fetchColumn(int $column = 0): mixed
    {
        throw new \RuntimeException('StubbedPdoStatement does not support fetchColumn(); use fetch() and read the column by name.');
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
