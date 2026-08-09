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

    /**
     * {@inheritDoc}
     *
     * Reads from the bound container. A container that has not been created yet reads as null,
     * the same answer a missing blob gives.
     */
    #[\Override]
    public function get(string $key): ?string
    {
        return $this->client->get($this->container, $key);
    }

    /**
     * {@inheritDoc}
     *
     * The bound container is created on the first write of this instance's lifetime and the
     * result remembered, so later writes cost one request rather than two.
     */
    #[\Override]
    public function put(string $key, string $body): void
    {
        $this->ensureContainer();
        $this->client->put($this->container, $key, $body);
    }

    /**
     * {@inheritDoc}
     *
     * Deletes from the bound container; the container itself is never created for a delete.
     */
    #[\Override]
    public function delete(string $key): void
    {
        $this->client->delete($this->container, $key);
    }

    /**
     * {@inheritDoc}
     *
     * Issues an Azure Get Blob Properties request against the bound container, so no body is
     * transferred.
     */
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

    /** Returns the name of the container every key on this client resolves against. */
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
