<?php

declare(strict_types=1);

namespace Quiote\Replay\Store;

/**
 * A {@see CassetteStoreInterface} whose store can also enumerate what it
 * holds. Separate from the base contract for the same reason
 * {@see \Quiote\Storage\ListableObjectStoreClientInterface} is separate from
 * {@see \Quiote\Storage\ObjectStoreClientInterface}: `cassette:list` and
 * `cassette:prune` need this, but a store that cannot list (an
 * object-store-backed one, per `docs/RECORD_REPLAY_PLAN.md` §12.8 -- it uses
 * `ListableObjectStoreClientInterface`'s own prefix-scan listing instead)
 * should not have to implement a method it cannot honour.
 */
interface ListableCassetteStoreInterface extends CassetteStoreInterface
{
    /**
     * Every cassette id currently in the store, as slugs (not raw ids -- a
     * store never learns a cassette's raw id without decoding it).
     *
     * @return list<string>
     */
    public function slugs(): array;
}
