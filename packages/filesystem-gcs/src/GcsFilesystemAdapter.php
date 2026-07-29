<?php

declare(strict_types=1);

namespace Quiote\Filesystem\Gcs;

use DateTimeImmutable;
use Quiote\Filesystem\FileNotFoundStorageException;
use Quiote\Filesystem\FilesystemAdapterInterface;
use Quiote\Filesystem\FilesystemStorageException;
use Quiote\Storage\Gcs\GcsClient;
use Quiote\Storage\Gcs\ObjectMetadata;
use Quiote\Storage\Gcs\GcsStorageException;

/**
 * {@see FilesystemAdapterInterface} wrapping the existing {@see GcsClient}
 * (HMAC interop-key REST client, no google/cloud-storage) as its transport.
 *
 * The underlying client has no list-bucket operation, so {@see listContents()}
 * always throws — see `Quiote\Filesystem\S3\S3FilesystemAdapter`'s docblock
 * for the reasoning. Applications that need a listing should keep it in their
 * own database beside the record that owns the files, or drive
 * {@see GcsClient::request()} — which signs an arbitrary request and returns
 * the raw response — directly.
 */
final readonly class GcsFilesystemAdapter implements FilesystemAdapterInterface
{
    public function __construct(
        private GcsClient $client,
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
            $this->client->put($this->key($path), $contents);
        } catch (GcsStorageException $e) {
            throw new FilesystemStorageException(sprintf('Failed writing file "%s": %s', $path, $e->getMessage()), 0, $e);
        }
    }

    #[\Override]
    public function delete(string $path): void
    {
        try {
            $this->client->delete($this->key($path));
        } catch (GcsStorageException $e) {
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
            throw new FilesystemStorageException(sprintf('GCS returned no Content-Length for file "%s".', $path));
        }

        return $size;
    }

    #[\Override]
    public function lastModified(string $path): DateTimeImmutable
    {
        $lastModified = $this->metadata($path)->lastModified;
        if ($lastModified === null) {
            throw new FilesystemStorageException(sprintf('GCS returned no usable Last-Modified for file "%s".', $path));
        }

        return $lastModified;
    }

    #[\Override]
    public function listContents(string $path = ''): array
    {
        throw new FilesystemStorageException('listContents() is not supported by the GCS filesystem adapter — the underlying GcsClient has no list-bucket endpoint. Track listings yourself, or build one on GcsClient::request().');
    }

    private function fetch(string $path): ?string
    {
        try {
            return $this->client->get($this->key($path));
        } catch (GcsStorageException $e) {
            throw new FilesystemStorageException(sprintf('Failed reading file "%s": %s', $path, $e->getMessage()), 0, $e);
        }
    }

    private function head(string $path): ?ObjectMetadata
    {
        try {
            return $this->client->head($this->key($path));
        } catch (GcsStorageException $e) {
            throw new FilesystemStorageException(sprintf('Failed reading metadata of file "%s": %s', $path, $e->getMessage()), 0, $e);
        }
    }

    /** @throws FileNotFoundStorageException if $path does not exist. */
    private function metadata(string $path): ObjectMetadata
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
