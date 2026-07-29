<?php

declare(strict_types=1);

namespace Quiote\Filesystem\S3;

use DateTimeImmutable;
use Quiote\Filesystem\FileNotFoundStorageException;
use Quiote\Filesystem\FilesystemAdapterInterface;
use Quiote\Filesystem\FilesystemStorageException;
use Quiote\Storage\S3\ObjectMetadata;
use Quiote\Storage\S3\S3Client;
use Quiote\Storage\S3\S3StorageException;

/**
 * {@see FilesystemAdapterInterface} wrapping the existing {@see S3Client}
 * (SigV4 REST client, no aws-sdk-php) as its transport.
 *
 * The underlying client has no list-bucket operation, so {@see listContents()}
 * always throws: a listing means paging ListObjectsV2 and folding
 * CommonPrefixes back into relative paths, which is both more than this
 * adapter should decide on a caller's behalf and, on a large prefix, more
 * round-trips than the interface's flat return value admits to. Applications
 * that need one should keep the listing in their own database beside the
 * record that owns the files, or drive {@see S3Client::request()} — which
 * signs an arbitrary request and returns the raw response — directly.
 */
final readonly class S3FilesystemAdapter implements FilesystemAdapterInterface
{
    public function __construct(
        private S3Client $client,
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
        } catch (S3StorageException $e) {
            throw new FilesystemStorageException(sprintf('Failed writing file "%s": %s', $path, $e->getMessage()), 0, $e);
        }
    }

    #[\Override]
    public function delete(string $path): void
    {
        try {
            $this->client->delete($this->key($path));
        } catch (S3StorageException $e) {
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
            throw new FilesystemStorageException(sprintf('S3 returned no Content-Length for file "%s".', $path));
        }

        return $size;
    }

    #[\Override]
    public function lastModified(string $path): DateTimeImmutable
    {
        $lastModified = $this->metadata($path)->lastModified;
        if ($lastModified === null) {
            throw new FilesystemStorageException(sprintf('S3 returned no usable Last-Modified for file "%s".', $path));
        }

        return $lastModified;
    }

    #[\Override]
    public function listContents(string $path = ''): array
    {
        throw new FilesystemStorageException('listContents() is not supported by the S3 filesystem adapter — the underlying S3Client has no list-bucket endpoint. Track listings yourself, or build one on S3Client::request().');
    }

    private function fetch(string $path): ?string
    {
        try {
            return $this->client->get($this->key($path));
        } catch (S3StorageException $e) {
            throw new FilesystemStorageException(sprintf('Failed reading file "%s": %s', $path, $e->getMessage()), 0, $e);
        }
    }

    private function head(string $path): ?ObjectMetadata
    {
        try {
            return $this->client->head($this->key($path));
        } catch (S3StorageException $e) {
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
