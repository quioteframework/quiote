<?php

declare(strict_types=1);

namespace Quiote\Replay\Cassette;

/**
 * The one shape an {@see EffectKind::Db} effect's `result` takes, so a consumer does not have to
 * guess which recorder wrote the cassette it is reading.
 *
 * Before this, each driver package answered the same question differently and every consumer was
 * written against exactly one of them:
 *
 *  - the PDO recorder wrote rows for a read and an integer affected-count for a write;
 *  - the Doctrine recorder wrote rows for both, so a write's affected count was lost;
 *  - the Eloquent recorder wrote `null` always, because its event fires after the rows have
 *    already gone back to the caller and there is nothing left to capture;
 *  - the Cycle recorder wrote a row count and never rows, for the same reason;
 *  - the Propulsion recorder wrote a keyed array with rows, columns and a truncation flag.
 *
 * So `StubbedPdoStatement`, written against the PDO shape, replayed an Eloquent cassette as zero
 * rows for every query and raised a `TypeError` on a Cycle one, and a Doctrine-recorded write
 * replayed with an affected count of zero regardless of what happened. This class makes the three
 * distinctions that actually matter explicit and separable:
 *
 *  - `rows === null` means **no rows were captured** -- the recorder cannot see them at this layer.
 *    Distinct from `rows === []`, which means the query genuinely returned nothing.
 *  - `affectedRows` is the write's own count, kept even when rows are also present.
 *  - `rowsTruncated` says a cap stopped the capture short, so a replay reading fewer rows than the
 *    original knows it is looking at a prefix rather than at drift.
 *
 * Serialized as a plain array because a cassette is JSON; {@see fromResult()} reads back both this
 * shape and every legacy one above, so cassettes recorded before it stay readable.
 */
final readonly class DbResult
{
    /**
     * @param list<array<array-key, mixed>>|null $rows Captured rows, or null when the recorder
     *        cannot see them.
     * @param int|null $affectedRows The statement's own affected-row count, when it reported one.
     * @param bool $rowsTruncated Whether a row cap stopped the capture short.
     */
    public function __construct(
        public ?array $rows,
        public ?int $affectedRows = null,
        public bool $rowsTruncated = false,
    ) {
    }

    /**
     * A read that captured its rows.
     *
     * @param array<array-key, array<array-key, mixed>> $rows
     */
    public static function rows(array $rows, bool $truncated = false): self
    {
        return new self(array_values($rows), null, $truncated);
    }

    /** A write, or any statement with no result set. */
    public static function affected(int $count): self
    {
        return new self(null, $count);
    }

    /**
     * A statement whose rows this recorder's seam cannot reach -- the Eloquent and Cycle shape.
     * `$affectedRows` is still recorded when the seam reports one.
     */
    public static function unobservedRows(?int $affectedRows = null): self
    {
        return new self(null, $affectedRows);
    }

    /**
     * Reads a recorded `result` back, accepting this shape and every shape the driver packages
     * produced before it.
     *
     * Returns null only when the value is nothing this class can describe at all, which a caller
     * should report as a malformed cassette rather than paper over.
     */
    public static function fromResult(mixed $result): ?self
    {
        if ($result instanceof self) {
            return $result;
        }
        if ($result === null) {
            // The Eloquent shape: a query happened and its rows were never observable.
            return self::unobservedRows();
        }
        if (is_int($result)) {
            // The PDO/Cycle write shape.
            return self::affected($result);
        }
        if (!is_array($result)) {
            return null;
        }
        if (array_is_list($result)) {
            // The PDO/Doctrine read shape: a bare list of rows.
            $rows = self::rowList($result);

            return $rows === null ? null : self::rows($rows);
        }

        // The keyed shapes: this class's own, and Propulsion's {row_count, rows, columns, truncated}.
        $rows = array_key_exists('rows', $result) ? $result['rows'] : null;
        $rowList = is_array($rows) ? self::rowList(array_values($rows)) : null;
        $affected = $result['affectedRows'] ?? $result['row_count'] ?? null;
        $truncated = (bool)($result['rowsTruncated'] ?? $result['rows_truncated'] ?? $result['truncated'] ?? false);

        return new self(
            $rowList,
            is_int($affected) ? $affected : null,
            $truncated,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rows' => $this->rows,
            'affectedRows' => $this->affectedRows,
            'rowsTruncated' => $this->rowsTruncated,
        ];
    }

    /**
     * @param list<mixed> $values
     * @return list<array<array-key, mixed>>|null
     */
    private static function rowList(array $values): ?array
    {
        $rows = [];
        foreach ($values as $row) {
            if (!is_array($row)) {
                return null;
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
