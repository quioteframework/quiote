<?php

declare(strict_types=1);

namespace Quiote\Replay\Db;

use PDO;

/**
 * Shared by {@see RecordingPdoStatement} and
 * {@see \Quiote\Replay\Replay\StubbedPdoStatement}: both serve a snapshotted result set with no
 * live cursor to delegate to, and both need to reformat a row into whichever `PDO::FETCH_*` mode
 * the caller asked for, entirely in PHP.
 *
 * Two entry points, because the two hold different shapes for a good reason.
 * {@see formatPositionalRow()} takes a positional row plus its column names, which is what the
 * recording side captures: an associative snapshot collapses duplicate column names, so
 * `SELECT a.id, b.id` would lose one and every positional mode rebuilt from it would be wrong.
 * {@see formatRow()} takes an associative row, which is what a cassette carries -- by then the
 * duplicate is already gone and there is nothing to recover.
 *
 * Deliberately supports only ASSOC/NUM/OBJ/BOTH/default -- see each
 * statement class's docblock for what is explicitly out of scope.
 */
trait PdoRowFormatting
{
    /**
     * @param array<array-key, mixed> $values Positional values, in column order.
     * @param list<string>|null $names Column names in the same order, or null when unknown.
     */
    private function formatPositionalRow(array $values, ?array $names, int $mode): mixed
    {
        $positional = array_values($values);
        if ($names === null) {
            // No names to build an associative view from, so only the positional modes can be
            // answered honestly.
            return match ($mode) {
                PDO::FETCH_NUM, PDO::FETCH_DEFAULT => $positional,
                default => throw new \RuntimeException(sprintf(
                    '%s cannot serve fetch mode %d for a row with no known column names.',
                    static::class,
                    $mode,
                )),
            };
        }

        // A later duplicate name overwrites an earlier one, exactly as PDO's own FETCH_ASSOC does.
        $assoc = [];
        foreach ($positional as $index => $value) {
            $assoc[$names[$index] ?? $index] = $value;
        }

        return match ($mode) {
            PDO::FETCH_ASSOC, PDO::FETCH_DEFAULT => $assoc,
            PDO::FETCH_NUM => $positional,
            PDO::FETCH_BOTH => $assoc + $positional,
            PDO::FETCH_OBJ => (object) $assoc,
            default => throw new \RuntimeException(sprintf('%s does not support fetch mode %d.', static::class, $mode)),
        };
    }

    /** @param array<array-key, mixed> $row An associative row, as a cassette stores one. */
    private function formatRow(array $row, int $mode): mixed
    {
        return match ($mode) {
            PDO::FETCH_ASSOC, PDO::FETCH_DEFAULT => $row,
            PDO::FETCH_NUM => array_values($row),
            PDO::FETCH_BOTH => $row + array_values($row),
            PDO::FETCH_OBJ => (object) $row,
            default => throw new \RuntimeException(sprintf('%s does not support fetch mode %d.', static::class, $mode)),
        };
    }
}
