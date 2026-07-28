<?php

declare(strict_types=1);

namespace Quiote\Filesystem\Azure;

use DateTimeImmutable;
use Quiote\Filesystem\FileNotFoundStorageException;
use Quiote\Filesystem\FilesystemAdapterInterface;
use Quiote\Filesystem\FilesystemStorageException;
use Quiote\Storage\Azure\AzureBlobClient;
use Quiote\Storage\Azure\AzureStorageException;

/**
 * {@see FilesystemAdapterInterface} wrapping the existing {@see AzureBlobClient}
 * (Shared-Key REST client) as its transport, against a fixed container (Azure
 * has no bucket-equivalent bound to the client itself, unlike S3/GCS).
 *
 * The underlying client only supports ensure-container/get/put/delete — no
 * blob-properties (HEAD) or list-blobs operation — so {@see size()},
 * {@see lastModified()}, and {@see listContents()} always throw. See
 * `Quiote\Filesystem\S3\S3FilesystemAdapter`'s docblock for the same
 * reasoning; extending AzureBlobClient with those operations is a separate,
 * larger follow-up.
 */
final readonly class AzureFilesystemAdapter implements FilesystemAdapterInterface
{
    public function __construct(
        private AzureBlobClient $client,
        private string $container,
        private string $keyPrefix = '',
    ) {
    }

    #[\Override]
    public function read(string $path): string
    {
        $body = $this->fetch($path);
        if ($body === null) {
            throw new FileNotFoundStorageException(sprintf('File "%s" does not exist.', $path));
        }
        return $body;
    }

    #[\Override]
    public function write(string $path, string $contents): void
    {
        try {
            $this->client->put($this->container, $this->key($path), $contents);
        } catch (AzureStorageException $e) {
            throw new FilesystemStorageException(sprintf('Failed writing file "%s": %s', $path, $e->getMessage()), 0, $e);
        }
    }

    #[\Override]
    public function delete(string $path): void
    {
        try {
            $this->client->delete($this->container, $this->key($path));
        } catch (AzureStorageException $e) {
            throw new FilesystemStorageException(sprintf('Failed deleting file "%s": %s', $path, $e->getMessage()), 0, $e);
        }
    }

    #[\Override]
    public function exists(string $path): bool
    {
        return $this->fetch($path) !== null;
    }

    #[\Override]
    public function size(string $path): int
    {
        throw new FilesystemStorageException('size() is not supported by the Azure filesystem adapter in v1 — the underlying AzureBlobClient has no blob-properties endpoint.');
    }

    #[\Override]
    public function lastModified(string $path): DateTimeImmutable
    {
        throw new FilesystemStorageException('lastModified() is not supported by the Azure filesystem adapter in v1 — the underlying AzureBlobClient has no blob-properties endpoint.');
    }

    #[\Override]
    public function listContents(string $path = ''): array
    {
        throw new FilesystemStorageException('listContents() is not supported by the Azure filesystem adapter in v1 — the underlying AzureBlobClient has no list-blobs endpoint.');
    }

    private function fetch(string $path): ?string
    {
        try {
            return $this->client->get($this->container, $this->key($path));
        } catch (AzureStorageException $e) {
            throw new FilesystemStorageException(sprintf('Failed reading file "%s": %s', $path, $e->getMessage()), 0, $e);
        }
    }

    private function key(string $path): string
    {
        return $this->keyPrefix . $path;
    }
}
