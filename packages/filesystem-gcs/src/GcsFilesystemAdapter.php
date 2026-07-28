<?php

declare(strict_types=1);

namespace Quiote\Filesystem\Gcs;

use DateTimeImmutable;
use Quiote\Filesystem\FileNotFoundStorageException;
use Quiote\Filesystem\FilesystemAdapterInterface;
use Quiote\Filesystem\FilesystemStorageException;
use Quiote\Storage\Gcs\GcsClient;
use Quiote\Storage\Gcs\GcsStorageException;

/**
 * {@see FilesystemAdapterInterface} wrapping the existing {@see GcsClient}
 * (HMAC interop-key REST client, no google/cloud-storage) as its transport.
 *
 * The underlying client only supports get/put/delete on a single object — no
 * HEAD or list-bucket operation — so {@see size()}, {@see lastModified()},
 * and {@see listContents()} always throw. See
 * `Quiote\Filesystem\S3\S3FilesystemAdapter`'s docblock for the same
 * reasoning; extending GcsClient with those operations is a separate,
 * larger follow-up.
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
        return $this->fetch($path) !== null;
    }

    #[\Override]
    public function size(string $path): int
    {
        throw new FilesystemStorageException('size() is not supported by the GCS filesystem adapter in v1 — the underlying GcsClient has no HEAD endpoint.');
    }

    #[\Override]
    public function lastModified(string $path): DateTimeImmutable
    {
        throw new FilesystemStorageException('lastModified() is not supported by the GCS filesystem adapter in v1 — the underlying GcsClient has no HEAD endpoint.');
    }

    #[\Override]
    public function listContents(string $path = ''): array
    {
        throw new FilesystemStorageException('listContents() is not supported by the GCS filesystem adapter in v1 — the underlying GcsClient has no list-bucket endpoint.');
    }

    private function fetch(string $path): ?string
    {
        try {
            return $this->client->get($this->key($path));
        } catch (GcsStorageException $e) {
            throw new FilesystemStorageException(sprintf('Failed reading file "%s": %s', $path, $e->getMessage()), 0, $e);
        }
    }

    private function key(string $path): string
    {
        return $this->keyPrefix . $path;
    }
}
