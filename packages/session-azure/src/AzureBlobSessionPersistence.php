<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

use JsonException;
use Quiote\Session\SessionPersistenceInterface;

/**
 * {@see SessionPersistenceInterface} storing one JSON blob per session id
 * (named `<sid>.json`) in a single Azure Blob container.
 */
final class AzureBlobSessionPersistence implements SessionPersistenceInterface
{
    private bool $containerEnsured = false;

    public function __construct(
        private readonly AzureBlobClient $client,
        private readonly string $container = 'quiote-sessions',
    ) {
    }

    #[\Override]
    public function load(string $sid): ?array
    {
        $payload = $this->client->get($this->container, $this->blobName($sid));
        if ($payload === null || $payload === '') {
            return null;
        }

        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $data */
    #[\Override]
    public function save(string $sid, array $data): void
    {
        $this->ensureContainer();
        $this->client->put(
            $this->container,
            $this->blobName($sid),
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    #[\Override]
    public function delete(string $sid): void
    {
        $this->client->delete($this->container, $this->blobName($sid));
    }

    private function ensureContainer(): void
    {
        if (!$this->containerEnsured) {
            $this->client->ensureContainerExists($this->container);
            $this->containerEnsured = true;
        }
    }

    private function blobName(string $sid): string
    {
        return "{$sid}.json";
    }
}
