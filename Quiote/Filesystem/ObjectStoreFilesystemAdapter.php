<?php

declare(strict_types=1);

namespace Quiote\Filesystem;

use DateTimeImmutable;
use Quiote\Storage\ObjectMetadata;
use Quiote\Storage\ObjectStoreClientInterface;
use Quiote\Storage\ObjectStoreException;

/**
 * A {@see FilesystemAdapterInterface} over any {@see ObjectStoreClientInterface}.
 *
 * Every keyed object store maps onto a filesystem the same way -- prefix the path to form a key,
 * translate a missing object into a not-found error, and read size and modification time out of
 * the object's metadata. That mapping is provider-independent, so it lives here once and the
 * provider packages supply only their client.
 *
 * Not a {@see ListableFilesystemInterface} itself: that needs a store that can enumerate its
 * keys, which not every {@see ObjectStoreClientInterface} can. {@see ListableObjectStoreFilesystemAdapter}
 * adds it for the ones that can, without every consumer of this class paying for the distinction.
 *
 * The provider name is carried for error messages only, so a failure says "S3 returned no
 * Content-Length" rather than something a reader has to trace back to a driver alias.
 *
 * @since      3.2.0
 */
readonly class ObjectStoreFilesystemAdapter implements FilesystemAdapterInterface
{
    /**
     * @param      ObjectStoreClientInterface $client The store, already bound to its bucket or
     *             container.
     * @param      string $providerName Named in error messages, e.g. 'S3', 'GCS', 'Azure'.
     * @param      string $keyPrefix Prepended to every path to form the object key.
     */
    public function __construct(
        protected ObjectStoreClientInterface $client,
        protected string $providerName,
        protected string $keyPrefix = '',
    ) {
    }

    /**
     * Returns the body of the object stored under $path.
     *
     * @throws     FileNotFoundStorageException If the store reports no object for that key.
     * @throws     FilesystemStorageException If the store call itself failed.
     */
    #[\Override]
    public function read(string $path): string
    {
        $body = $this->fetch($path);
        if ($body === null) {
            throw new FileNotFoundStorageException(sprintf('File "%s" does not exist.', $path));
        }

        return $body;
    }

    /**
     * Stores $contents as the object under $path, replacing any existing object.
     *
     * @throws     FilesystemStorageException If the store rejected the put; the provider's own
     *             exception is kept as the previous exception.
     */
    #[\Override]
    public function write(string $path, string $contents): void
    {
        try {
            $this->client->put($this->key($path), $contents);
        } catch (ObjectStoreException $e) {
            throw new FilesystemStorageException(sprintf('Failed writing file "%s": %s', $path, $e->getMessage()), 0, $e);
        }
    }

    /**
     * Deletes the object under $path.
     *
     * Deleting a key the store does not hold is not an error — object stores treat delete as
     * idempotent — so only a transport or authorization failure is reported.
     *
     * @throws     FilesystemStorageException If the store rejected the delete.
     */
    #[\Override]
    public function delete(string $path): void
    {
        try {
            $this->client->delete($this->key($path));
        } catch (ObjectStoreException $e) {
            throw new FilesystemStorageException(sprintf('Failed deleting file "%s": %s', $path, $e->getMessage()), 0, $e);
        }
    }

    /**
     * Reports whether the store holds an object under $path.
     *
     * Costs a metadata request, not a download.
     *
     * @throws     FilesystemStorageException If the metadata request failed for a reason other than
     *             the object being absent.
     */
    #[\Override]
    public function exists(string $path): bool
    {
        return $this->head($path) !== null;
    }

    /**
     * Returns the object's size in bytes, taken from its Content-Length metadata.
     *
     * A provider that answers the metadata request without a usable Content-Length is a failure,
     * not a zero-length file, and is reported naming the provider.
     *
     * @throws     FileNotFoundStorageException If the store holds no object under $path.
     * @throws     FilesystemStorageException If the metadata carried no content length.
     */
    #[\Override]
    public function size(string $path): int
    {
        $size = $this->metadata($path)->contentLength;
        if ($size === null) {
            throw new FilesystemStorageException(sprintf(
                '%s returned no Content-Length for file "%s".',
                $this->providerName,
                $path
            ));
        }

        return $size;
    }

    /**
     * Returns the object's modification time, taken from its Last-Modified metadata.
     *
     * A provider that omits the header, or sends one that could not be parsed, is reported as a
     * failure naming the provider rather than defaulting to the current time.
     *
     * @throws     FileNotFoundStorageException If the store holds no object under $path.
     * @throws     FilesystemStorageException If the metadata carried no usable modification time.
     */
    #[\Override]
    public function lastModified(string $path): DateTimeImmutable
    {
        $lastModified = $this->metadata($path)->lastModified;
        if ($lastModified === null) {
            throw new FilesystemStorageException(sprintf(
                '%s returned no usable Last-Modified for file "%s".',
                $this->providerName,
                $path
            ));
        }

        return $lastModified;
    }

    private function fetch(string $path): ?string
    {
        try {
            return $this->client->get($this->key($path));
        } catch (ObjectStoreException $e) {
            throw new FilesystemStorageException(sprintf('Failed reading file "%s": %s', $path, $e->getMessage()), 0, $e);
        }
    }

    private function head(string $path): ?ObjectMetadata
    {
        try {
            return $this->client->head($this->key($path));
        } catch (ObjectStoreException $e) {
            throw new FilesystemStorageException(sprintf('Failed reading metadata of file "%s": %s', $path, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @throws     FileNotFoundStorageException If $path does not exist.
     */
    private function metadata(string $path): ObjectMetadata
    {
        $metadata = $this->head($path);
        if ($metadata === null) {
            throw new FileNotFoundStorageException(sprintf('File "%s" does not exist.', $path));
        }

        return $metadata;
    }

    protected function key(string $path): string
    {
        return $this->keyPrefix . $path;
    }
}
