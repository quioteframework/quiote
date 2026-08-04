<?php

declare(strict_types=1);

namespace Quiote\Filesystem\Gcs;

use Quiote\Storage\Gcs\GcsClient;
use Quiote\Filesystem\ObjectStoreFilesystemAdapter;

/**
 * {@see \Quiote\Filesystem\FilesystemAdapterInterface} over {@see GcsClient} (HMAC interop-key
 * REST client, no google/cloud-storage).
 *
 * The path-to-key mapping and the error translation live in
 * {@see ObjectStoreFilesystemAdapter}, shared with the other object-store drivers; this class
 * supplies the client and the provider name that appears in its messages.
 *
 * Not a {@see \Quiote\Filesystem\ListableFilesystemInterface}: the client has no list-bucket
 * operation — see `Quiote\Filesystem\S3\S3FilesystemAdapter`'s docblock for the reasoning.
 */
final readonly class GcsFilesystemAdapter extends ObjectStoreFilesystemAdapter
{
    public function __construct(GcsClient $client, string $objectPrefix = '')
    {
        parent::__construct($client, 'GCS', $objectPrefix);
    }
}
