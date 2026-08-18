<?php

declare(strict_types=1);

namespace Quiote\Filesystem\Gcs;

use Quiote\Storage\Gcs\GcsClient;
use Quiote\Filesystem\ListableObjectStoreFilesystemAdapter;

/**
 * {@see \Quiote\Filesystem\ListableFilesystemInterface} over {@see GcsClient} (HMAC interop-key
 * REST client, no google/cloud-storage).
 *
 * The path-to-key mapping, the error translation and the listing live in
 * {@see ListableObjectStoreFilesystemAdapter}, shared with the other object-store drivers; this
 * class supplies the client and the provider name that appears in its messages.
 */
final readonly class GcsFilesystemAdapter extends ListableObjectStoreFilesystemAdapter
{
    public function __construct(GcsClient $client, string $objectPrefix = '')
    {
        parent::__construct($client, 'GCS', $objectPrefix);
    }
}
