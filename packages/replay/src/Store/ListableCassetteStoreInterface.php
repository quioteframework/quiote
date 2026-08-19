<?php

declare(strict_types=1);

namespace Quiote\Replay\Store;

/**
 * A {@see CassetteStoreInterface} whose store can also enumerate what it
 * holds. Separate from the base contract for the same reason
 * {@see \Quiote\Storage\ListableObjectStoreClientInterface} is separate from
 * {@see \Quiote\Storage\ObjectStoreClientInterface}: `cassette:list` and
 * `cassette:prune` need this, but a store that genuinely cannot list
 * (a pure key-value backend with no enumeration API at all) should not have
 * to implement a method it cannot honour. Every store this package or its
 * driver packages ship today (file, PDO, object-store-backed) does implement
 * it, each over its own underlying listing mechanism.
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
