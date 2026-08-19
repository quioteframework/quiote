<?php

declare(strict_types=1);

namespace Quiote\Replay\Store;

use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteId;

/**
 * Where a cassette is written and read back from. Listing is deliberately
 * not part of this base contract -- see {@see ListableCassetteStoreInterface},
 * which {@see FileCassetteStore}, a PDO-backed store, and an object-store-backed
 * one all implement, each over its own underlying listing mechanism (a
 * directory scan, a `SELECT`, or `Quiote\Storage\ListableObjectStoreClientInterface`'s
 * own prefix-scan listing).
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
