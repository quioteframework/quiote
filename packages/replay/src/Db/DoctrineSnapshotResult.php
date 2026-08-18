<?php

declare(strict_types=1);

namespace Quiote\Replay\Db;

use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Exception\InvalidColumnIndex;

/**
 * An in-memory {@see Result} snapshot: what `DoctrineRecordingStatement`/
 * `DoctrineRecordingConnection` hand back to the caller in place of the real,
 * now-consumed `Result` once a query's rows have been captured for the
 * ledger, so the caller's own fetch loop still works normally.
 *
 * Deliberately not `Doctrine\DBAL\Cache\ArrayResult`: that class is marked
 * `@internal` to DBAL's own caching layer and is not covered by DBAL's
 * backward-compatibility promise, so depending on it here would be a fragile
 * coupling to an implementation detail rather than a public contract.
 *
 * `getColumnName()` is not part of {@see Result}'s enforced interface (it is
 * declared only via the interface's `@method` docblock, and DBAL's own
 * `AbstractResultMiddleware` checks `method_exists()` before calling it) --
 * this class implements it anyway, derived from the snapshotted rows' own
 * keys, for parity with a real driver result.
 */
final class DoctrineSnapshotResult implements Result
{
    private int $cursor = 0;

    /** @var list<string> */
    private readonly array $columnNames;

    /**
     * @param list<array<string, mixed>> $rows Snapshotted rows, associative by column name.
     * @param int|numeric-string $affectedRows The real statement's own rowCount(), captured before snapshotting.
     */
    public function __construct(
        private array $rows,
        private readonly int|string $affectedRows,
    ) {
        $this->columnNames = $this->rows === [] ? [] : array_keys($this->rows[0]);
    }

    public function fetchNumeric(): array|false
    {
        $row = $this->fetchAssociative();

        return $row === false ? false : array_values($row);
    }

    /** @return array<string, mixed>|false */
    public function fetchAssociative(): array|false
    {
        return $this->rows[$this->cursor++] ?? false;
    }

    public function fetchOne(): mixed
    {
        $row = $this->fetchAssociative();

        return $row === false ? false : array_values($row)[0] ?? false;
    }

    /** @return list<list<mixed>> */
    public function fetchAllNumeric(): array
    {
        return array_map(array_values(...), $this->remainingRows());
    }

    /** @return list<array<string, mixed>> */
    public function fetchAllAssociative(): array
    {
        return $this->remainingRows();
    }

    /** @return list<mixed> */
    public function fetchFirstColumn(): array
    {
        return array_map(
            static fn(array $row): mixed => array_values($row)[0] ?? null,
            $this->remainingRows(),
        );
    }

    public function rowCount(): int|string
    {
        return $this->affectedRows;
    }

    public function columnCount(): int
    {
        return count($this->columnNames);
    }

    public function getColumnName(int $index): string
    {
        return $this->columnNames[$index] ?? throw InvalidColumnIndex::new($index);
    }

    public function free(): void
    {
        $this->rows = [];
        $this->cursor = 0;
    }

    /** @return list<array<string, mixed>> */
    private function remainingRows(): array
    {
        $remaining = array_slice($this->rows, $this->cursor);
        $this->cursor = count($this->rows);

        return $remaining;
    }
}
