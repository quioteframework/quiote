<?php

declare(strict_types=1);

namespace Quiote\Replay\Db;

use PDO;

/**
 * Shared by {@see RecordingPdoStatement} and
 * {@see \Quiote\Replay\Replay\StubbedPdoStatement}: both snapshot a result set
 * as `list<array<string, mixed>>` of associative rows and need to reformat a
 * row into whichever `PDO::FETCH_*` mode the caller asked for, entirely in
 * PHP, with no live cursor to delegate to.
 *
 * Deliberately supports only ASSOC/NUM/OBJ/BOTH/default -- see each
 * statement class's docblock for what is explicitly out of scope.
 */
trait PdoRowFormatting
{
    /** @param array<string, mixed> $row */
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
