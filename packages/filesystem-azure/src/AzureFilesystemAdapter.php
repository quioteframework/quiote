<?php

declare(strict_types=1);

namespace Quiote\Filesystem\Azure;

use DateTimeImmutable;
use Quiote\Filesystem\FileNotFoundStorageException;
use Quiote\Filesystem\FilesystemAdapterInterface;
use Quiote\Filesystem\FilesystemStorageException;
use Quiote\Storage\Azure\AzureBlobClient;
use Quiote\Storage\Azure\AzureStorageException;
use Quiote\Storage\Azure\BlobMetadata;

/**
 * {@see FilesystemAdapterInterface} wrapping the existing {@see AzureBlobClient}
 * (Shared-Key REST client) as its transport, against a fixed container (Azure
 * has no bucket-equivalent bound to the client itself, unlike S3/GCS).
 *
 * The underlying client has no list-blobs operation, so {@see listContents()}
 * always throws — see `Quiote\Filesystem\S3\S3FilesystemAdapter`'s docblock
 * for the reasoning. Applications that need a listing should keep it in their
 * own database beside the record that owns the files, or drive
 * {@see AzureBlobClient::request()} — which signs an arbitrary request and
 * returns the raw response — directly.
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
        return $this->head($path) !== null;
    }

    #[\Override]
    public function size(string $path): int
    {
        $size = $this->metadata($path)->contentLength;
        if ($size === null) {
            throw new FilesystemStorageException(sprintf('Azure returned no Content-Length for file "%s".', $path));
        }

        return $size;
    }

    #[\Override]
    public function lastModified(string $path): DateTimeImmutable
    {
        $lastModified = $this->metadata($path)->lastModified;
        if ($lastModified === null) {
            throw new FilesystemStorageException(sprintf('Azure returned no usable Last-Modified for file "%s".', $path));
        }

        return $lastModified;
    }

    #[\Override]
    public function listContents(string $path = ''): array
    {
        throw new FilesystemStorageException('listContents() is not supported by the Azure filesystem adapter — the underlying AzureBlobClient has no list-blobs endpoint. Track listings yourself, or build one on AzureBlobClient::request().');
    }

    private function fetch(string $path): ?string
    {
        try {
            return $this->client->get($this->container, $this->key($path));
        } catch (AzureStorageException $e) {
            throw new FilesystemStorageException(sprintf('Failed reading file "%s": %s', $path, $e->getMessage()), 0, $e);
        }
    }

    private function head(string $path): ?BlobMetadata
    {
        try {
            return $this->client->head($this->container, $this->key($path));
        } catch (AzureStorageException $e) {
            throw new FilesystemStorageException(sprintf('Failed reading metadata of file "%s": %s', $path, $e->getMessage()), 0, $e);
        }
    }

    /** @throws FileNotFoundStorageException if $path does not exist. */
    private function metadata(string $path): BlobMetadata
    {
        $metadata = $this->head($path);
        if ($metadata === null) {
            throw new FileNotFoundStorageException(sprintf('File "%s" does not exist.', $path));
        }

        return $metadata;
    }

    private function key(string $path): string
    {
        return $this->keyPrefix . $path;
    }
}
