<?php

declare(strict_types=1);

namespace Quiote\Replay\Store;

use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteId;

/**
 * Where a cassette is written and read back from. Listing is deliberately
 * not part of this base contract -- see {@see ListableCassetteStoreInterface},
 * which {@see FileCassetteStore} and a PDO-backed store implement but an
 * object-store-backed one (per `docs/RECORD_REPLAY_PLAN.md` §12.8) would not,
 * using `Quiote\Storage\ListableObjectStoreClientInterface`'s own prefix-scan
 * listing instead.
 */
interface CassetteStoreInterface
{
    /** @throws \Quiote\Exception\StorageException if the cassette could not be written. */
    public function put(CassetteId $id, Cassette $cassette): void;

    /** Null when no cassette is stored under this id. */
    public function get(CassetteId $id): ?Cassette;

    public function has(CassetteId $id): bool;

    /** Removes the cassette at $id. Best-effort: an id that does not exist is not an error. */
    public function delete(CassetteId $id): void;
}
