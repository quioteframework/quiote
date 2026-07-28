<?php

declare(strict_types=1);

namespace Quiote\Filesystem\S3;

use DateTimeImmutable;
use Quiote\Filesystem\FileNotFoundStorageException;
use Quiote\Filesystem\FilesystemAdapterInterface;
use Quiote\Filesystem\FilesystemStorageException;
use Quiote\Storage\S3\S3Client;
use Quiote\Storage\S3\S3StorageException;

/**
 * {@see FilesystemAdapterInterface} wrapping the existing {@see S3Client}
 * (SigV4 REST client, no aws-sdk-php) as its transport.
 *
 * The underlying client only supports get/put/delete on a single object — no
 * HEAD or list-bucket operation — so {@see size()}, {@see lastModified()},
 * and {@see listContents()} always throw. Extending S3Client with those
 * operations is a separate, larger follow-up (see FEATURE_GAPS.md); wiring
 * the existing three operations into the new interface is the scope here.
 * {@see exists()} is implemented via a GET, which is wasteful for large
 * objects but the only option without a HEAD call.
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
        return $this->fetch($path) !== null;
    }

    #[\Override]
    public function size(string $path): int
    {
        throw new FilesystemStorageException('size() is not supported by the S3 filesystem adapter in v1 — the underlying S3Client has no HEAD endpoint.');
    }

    #[\Override]
    public function lastModified(string $path): DateTimeImmutable
    {
        throw new FilesystemStorageException('lastModified() is not supported by the S3 filesystem adapter in v1 — the underlying S3Client has no HEAD endpoint.');
    }

    #[\Override]
    public function listContents(string $path = ''): array
    {
        throw new FilesystemStorageException('listContents() is not supported by the S3 filesystem adapter in v1 — the underlying S3Client has no list-bucket endpoint.');
    }

    private function fetch(string $path): ?string
    {
        try {
            return $this->client->get($this->key($path));
        } catch (S3StorageException $e) {
            throw new FilesystemStorageException(sprintf('Failed reading file "%s": %s', $path, $e->getMessage()), 0, $e);
        }
    }

    private function key(string $path): string
    {
        return $this->keyPrefix . $path;
    }
}
