<?php

declare(strict_types=1);

namespace Quiote\Replay\Index;

use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteId;
use Throwable;

/**
 * Stands in for an index that could not be constructed, so a misconfigured one reports itself
 * through {@see CassetteIndexChain}'s existing failure aggregation instead of aborting the build of
 * every other index -- see {@see CassetteIndexRegistry::build()} for the configuration that made
 * that the common case rather than a corner one.
 *
 * Throws rather than returning null, deliberately: a null is a decline ("nothing to find here"),
 * and "this index is misconfigured" is not the same answer. The chain records it as a failure and
 * moves on, and names it in the aggregate error if nothing else resolves -- which is exactly the
 * information a developer needs to fix the configuration.
 */
final readonly class UnavailableIndex implements CassetteIndexInterface
{
    public function __construct(private Throwable $reason)
    {
    }

    #[\Override]
    public function resolve(CassetteId $id, IndexHints $hints): ?Cassette
    {
        throw new CassetteIndexException(sprintf(
            'this index could not be built (%s: %s)',
            $this->reason::class,
            $this->reason->getMessage(),
        ), 0, $this->reason);
    }
}
