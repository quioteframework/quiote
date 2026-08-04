<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

use Quiote\Storage\ObjectMetadata;
use Quiote\Storage\ObjectStoreClientInterface;

/**
 * {@see AzureBlobClient} bound to one container, so it satisfies
 * {@see ObjectStoreClientInterface} like the S3 and GCS clients do.
 *
 * Azure takes the container per call, where S3 and GCS bind the bucket to the client itself.
 * That is the only shape difference between the three, and binding it here is what lets a
 * consumer be written once against the interface instead of once per provider.
 *
 * The container is created on first write, as {@see AzureBlobClient::ensureContainerExists()}
 * allows -- a read against a container that does not exist answers null, which is the same thing
 * a read of an absent blob answers.
 *
 * @since      3.2.0
 */
final class AzureBlobContainerClient implements ObjectStoreClientInterface
{
    private bool $containerEnsured = false;

    public function __construct(
        private readonly AzureBlobClient $client,
        private readonly string $container,
    ) {
    }

    #[\Override]
    public function get(string $key): ?string
    {
        return $this->client->get($this->container, $key);
    }

    #[\Override]
    public function put(string $key, string $body): void
    {
        $this->ensureContainer();
        $this->client->put($this->container, $key, $body);
    }

    #[\Override]
    public function delete(string $key): void
    {
        $this->client->delete($this->container, $key);
    }

    #[\Override]
    public function head(string $key): ?ObjectMetadata
    {
        return $this->client->head($this->container, $key);
    }

    /**
     * The underlying client, for the Azure-specific operations this contract does not cover.
     */
    public function blobClient(): AzureBlobClient
    {
        return $this->client;
    }

    public function container(): string
    {
        return $this->container;
    }

    private function ensureContainer(): void
    {
        if (!$this->containerEnsured) {
            $this->client->ensureContainerExists($this->container);
            $this->containerEnsured = true;
        }
    }
}
