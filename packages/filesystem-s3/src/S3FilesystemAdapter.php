<?php

declare(strict_types=1);

namespace Quiote\Filesystem\S3;

use Quiote\Storage\S3\S3Client;
use Quiote\Filesystem\ListableObjectStoreFilesystemAdapter;

/**
 * {@see \Quiote\Filesystem\ListableFilesystemInterface} over {@see S3Client} (SigV4 REST client,
 * no aws-sdk-php).
 *
 * The path-to-key mapping, the error translation and the listing (paging ListObjectsV2, folding
 * CommonPrefixes back into relative paths) live in {@see ListableObjectStoreFilesystemAdapter},
 * shared with the other object-store drivers; this class supplies the client and the provider
 * name that appears in its messages.
 */
final readonly class S3FilesystemAdapter extends ListableObjectStoreFilesystemAdapter
{
    public function __construct(S3Client $client, string $keyPrefix = '')
    {
        parent::__construct($client, 'S3', $keyPrefix);
    }
}
