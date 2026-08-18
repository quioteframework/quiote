<?php

declare(strict_types=1);

namespace Quiote\Storage;

/**
 * Read, write, remove and stat a single object in a flat keyed store.
 *
 * The operations every object store client supports unconditionally. Deliberately narrow beyond
 * that: no copy or move, no ACLs, no multipart. Listing is on {@see ListableObjectStoreClientInterface}
 * instead, since not every store built on this interface can offer it -- see that interface's
 * docblock. A provider client exposes its full API on its own concrete class -- this is the part
 * consumers can be written against once.
 *
 * Keys are flat strings. A provider whose API takes a container or bucket per call binds it at
 * construction, so a consumer never has to know which shape it is talking to.
 *
 * @since      3.2.0
 */
interface ObjectStoreClientInterface
{
    /**
     * The object's contents, or null when no object exists at $key.
     *
     * @throws     ObjectStoreException On a transport or provider failure, as distinct from a
     *             missing object.
     */
    public function get(string $key): ?string;

    /**
     * Create or replace the object at $key.
     *
     * @throws     ObjectStoreException If the write does not succeed.
     */
    public function put(string $key, string $body): void;

    /**
     * Remove the object at $key. Best-effort: a key that does not exist is not an error.
     *
     * @throws     ObjectStoreException On a transport or provider failure.
     */
    public function delete(string $key): void;

    /**
     * The object's metadata, or null when no object exists at $key.
     *
     * @throws     ObjectStoreException On a transport or provider failure.
     */
    public function head(string $key): ?ObjectMetadata;
}
