<?php

declare(strict_types=1);

namespace Quiote\Replay\Store\Storage\Index;

use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Index\CassetteIndexException;
use Quiote\Replay\Index\CassetteIndexInterface;
use Quiote\Replay\Index\IndexHints;
use Quiote\Storage\ObjectStoreClientInterface;

/**
 * The zero-dependency, always-works fallback: a key pasted straight out of a pointer log line,
 * fetched from the object store directly. Declines (returns null) when `--key` was not given --
 * this index has nothing to try without one -- but a given key that does not resolve to a real
 * object is a genuine failure, since the developer pointed at a specific location expecting it to
 * exist.
 */
final readonly class ExplicitKeyIndex implements CassetteIndexInterface
{
    public function __construct(
        private ObjectStoreClientInterface $client,
        private CassetteCodec $codec = new CassetteCodec(),
    ) {
    }

    #[\Override]
    public function resolve(CassetteId $id, IndexHints $hints): ?Cassette
    {
        if ($hints->key === null || $hints->key === '') {
            return null;
        }

        $blob = $this->client->get($hints->key);
        if ($blob === null) {
            throw new CassetteIndexException(sprintf('No object exists at the given key "%s".', $hints->key));
        }

        return $this->codec->decode($blob);
    }
}
