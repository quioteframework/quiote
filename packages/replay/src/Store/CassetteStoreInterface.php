<?php

declare(strict_types=1);

namespace Quiote\Replay\Store;

use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteId;

/**
 * Where a cassette is written and read back from. `list()` is deliberately
 * not part of this contract yet -- no store beyond the file default exists
 * to need `Quiote\Storage\ListableObjectStoreClientInterface` (per
 * `docs/RECORD_REPLAY_PLAN.md` §12.8) until a non-file store lands; until
 * then `cassette:list` enumerates {@see FileCassetteStore}'s directory
 * directly.
 */
interface CassetteStoreInterface
{
    /** @throws \Quiote\Exception\StorageException if the cassette could not be written. */
    public function put(CassetteId $id, Cassette $cassette): void;

    /** Null when no cassette is stored under this id. */
    public function get(CassetteId $id): ?Cassette;

    public function has(CassetteId $id): bool;
}
