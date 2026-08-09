<?php

declare(strict_types=1);

namespace Quiote\Session;

use Quiote\Storage\ObjectStoreClientInterface;

/**
 * A {@see SessionPersistenceInterface} storing one object per session id in any
 * {@see ObjectStoreClientInterface}.
 *
 * Every object store holds a session the same way: derive a key from the id, write the encoded
 * payload, read it back, delete it. That is provider-independent, so it lives here once and the
 * provider packages supply only their client.
 *
 * There is no garbage collection: an object store has no cheap way to enumerate expired sessions
 * (see {@see \Quiote\Filesystem\ListableFilesystemInterface} for why these clients cannot list),
 * so expiry belongs to a bucket lifecycle rule configured alongside the store rather than to a
 * pass on the request path.
 *
 * @since      3.2.0
 */
class ObjectStoreSessionPersistence implements SessionPersistenceInterface
{
    /**
     * @param      ObjectStoreClientInterface $client The store, already bound to its bucket or
     *             container.
     * @param      string $keyPrefix Prepended to every session id to form the object key.
     * @param      string $keySuffix Appended to it, e.g. '.json' so the stored object is
     *             recognisable in a bucket listing.
     * @param      SessionCodecInterface $codec Defaults to the portable codec: for an object
     *             store the round-trip dominates, and a readable stored object is worth more than
     *             a compact one.
     */
    public function __construct(
        private readonly ObjectStoreClientInterface $client,
        private readonly string $keyPrefix = 'sessions/',
        private readonly string $keySuffix = '.json',
        private readonly SessionCodecInterface $codec = new SessionCodec(preferBinary: false),
    ) {
    }

    /**
     * Fetches the session's object from the store and decodes it.
     *
     * Returns null when the store has no object under the derived key or the
     * object is empty; a missing key is an ordinary miss, not an error.
     */
    #[\Override]
    public function load(string $sid): ?array
    {
        $payload = $this->client->get($this->key($sid));
        if ($payload === null || $payload === '') {
            return null;
        }

        return $this->codec->decode($payload);
    }

    /**
     * @param      array<string, mixed> $data
     */
    #[\Override]
    public function save(string $sid, array $data): void
    {
        $this->client->put($this->key($sid), $this->codec->encode($data));
    }

    /**
     * Deletes the session's object from the store.
     *
     * Delegates straight to the client, which treats an absent key as a no-op.
     */
    #[\Override]
    public function delete(string $sid): void
    {
        $this->client->delete($this->key($sid));
    }

    protected function key(string $sid): string
    {
        return $this->keyPrefix . $sid . $this->keySuffix;
    }
}
