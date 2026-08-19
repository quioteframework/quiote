<?php

declare(strict_types=1);

namespace Quiote\Replay\Index;

use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteId;

/**
 * Resolves a bare cassette id (plus whatever hints the developer gave on the command line) to a
 * decoded {@see Cassette} -- the "id from a log line to a cassette on disk" chain, tried in order:
 * an explicit `--key`, then `log-analytics`, then a date-hinted `prefix-scan`.
 */
interface CassetteIndexInterface
{
    /**
     * Returns null when this index has nothing to try for the given id/hints -- not configured,
     * no matching hint present, or a legitimate zero-result lookup. That is the designed
     * "try the next index in the chain" signal, not an error. A genuine failure (a malformed
     * hint, a broken query, an auth error, or a pointer whose payload has already expired) throws
     * {@see CassetteIndexException} instead, so a misconfigured or broken index never
     * masquerades as "not found here."
     */
    public function resolve(CassetteId $id, IndexHints $hints): ?Cassette;
}
