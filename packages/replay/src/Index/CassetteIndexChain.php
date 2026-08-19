<?php

declare(strict_types=1);

namespace Quiote\Replay\Index;

use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteId;

/**
 * Tries each {@see CassetteIndexInterface} in order and answers the first cassette resolved, the
 * same "try each, fall through on decline, aggregate on total failure" shape
 * `Quiote\Storage\Azure\ChainedTokenProvider` already uses for token providers. A `null` from an
 * index is a decline (try the next); a {@see CassetteIndexException} is recorded and also falls
 * through, so one broken/unconfigured index never blocks the others -- but if every index either
 * declines or fails, the aggregate exception names every failure, not just "not found."
 */
final class CassetteIndexChain
{
    /**
     * @param list<CassetteIndexInterface> $indexes
     * @throws CassetteIndexException If no index in the chain resolved the id.
     */
    public static function resolve(array $indexes, CassetteId $id, IndexHints $hints): Cassette
    {
        $failures = [];
        foreach ($indexes as $index) {
            try {
                $cassette = $index->resolve($id, $hints);
            } catch (CassetteIndexException $e) {
                $failures[] = sprintf('%s: %s', $index::class, $e->getMessage());
                continue;
            }
            if ($cassette !== null) {
                return $cassette;
            }
        }

        throw new CassetteIndexException(sprintf(
            'No index could resolve cassette "%s"%s',
            $id->raw,
            $failures !== []
                ? ': ' . implode('; ', $failures)
                : ' (no cassette index is configured, or none had a matching hint to try).',
        ));
    }
}
