<?php

declare(strict_types=1);

namespace Quiote\Filesystem\S3;

use Quiote\Storage\S3\S3Client;
use Quiote\Filesystem\ObjectStoreFilesystemAdapter;

/**
 * {@see \Quiote\Filesystem\FilesystemAdapterInterface} over {@see S3Client} (SigV4 REST client,
 * no aws-sdk-php).
 *
 * The path-to-key mapping and the error translation live in
 * {@see ObjectStoreFilesystemAdapter}, shared with the other object-store drivers; this class
 * supplies the client and the provider name that appears in its messages.
 *
 * Not a {@see \Quiote\Filesystem\ListableFilesystemInterface}: the client has no list-bucket
 * operation, and a listing would mean paging ListObjectsV2 and folding CommonPrefixes back into
 * relative paths — both more than this adapter should decide on a caller's behalf and, on a large
 * prefix, more round-trips than a flat return value admits to. Applications that need a listing
 * should keep it in their own database beside the record that owns the files, or drive
 * {@see S3Client::request()} — which signs an arbitrary request and returns the raw response —
 * directly.
 */
final readonly class S3FilesystemAdapter extends ObjectStoreFilesystemAdapter
{
    public function __construct(S3Client $client, string $keyPrefix = '')
    {
        parent::__construct($client, 'S3', $keyPrefix);
    }
}
