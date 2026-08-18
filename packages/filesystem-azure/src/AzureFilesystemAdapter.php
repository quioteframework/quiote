<?php

declare(strict_types=1);

namespace Quiote\Filesystem\Azure;

use Quiote\Filesystem\ListableObjectStoreFilesystemAdapter;
use Quiote\Storage\Azure\AzureBlobClient;
use Quiote\Storage\Azure\AzureBlobContainerClient;

/**
 * {@see \Quiote\Filesystem\ListableFilesystemInterface} over {@see AzureBlobClient}, against a
 * fixed container.
 *
 * Azure takes the container per call where S3 and GCS bind the bucket to the client, so the
 * client is wrapped in an {@see AzureBlobContainerClient} that binds it. Everything after that,
 * the path-to-key mapping, the error translation, container creation on first write, the listing,
 * is the shared behaviour in {@see ListableObjectStoreFilesystemAdapter} and the container facade.
 */
final readonly class AzureFilesystemAdapter extends ListableObjectStoreFilesystemAdapter
{
    public function __construct(AzureBlobClient $client, string $container, string $keyPrefix = '')
    {
        parent::__construct(new AzureBlobContainerClient($client, $container), 'Azure', $keyPrefix);
    }
}
