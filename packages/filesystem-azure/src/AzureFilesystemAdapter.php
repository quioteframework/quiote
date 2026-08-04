<?php

declare(strict_types=1);

namespace Quiote\Filesystem\Azure;

use Quiote\Filesystem\ObjectStoreFilesystemAdapter;
use Quiote\Storage\Azure\AzureBlobClient;
use Quiote\Storage\Azure\AzureBlobContainerClient;

/**
 * {@see \Quiote\Filesystem\FilesystemAdapterInterface} over {@see AzureBlobClient}
 * (Shared-Key REST client), against a fixed container.
 *
 * Azure takes the container per call where S3 and GCS bind the bucket to the client, so the
 * client is wrapped in an {@see AzureBlobContainerClient} that binds it. Everything after that --
 * the path-to-key mapping, the error translation, container creation on first write -- is the
 * shared behaviour in {@see ObjectStoreFilesystemAdapter} and the container facade.
 *
 * Not a {@see \Quiote\Filesystem\ListableFilesystemInterface}: the client has no list-blobs
 * operation — see `Quiote\Filesystem\S3\S3FilesystemAdapter`'s docblock for the reasoning.
 */
final readonly class AzureFilesystemAdapter extends ObjectStoreFilesystemAdapter
{
    public function __construct(AzureBlobClient $client, string $container, string $keyPrefix = '')
    {
        parent::__construct(new AzureBlobContainerClient($client, $container), 'Azure', $keyPrefix);
    }
}
