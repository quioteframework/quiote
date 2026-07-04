<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

use JsonException;
use Quiote\Session\SessionPersistenceInterface;

/**
 * {@see SessionPersistenceInterface} storing one entity per session id in a
 * single Azure Table Storage table — cheaper than {@see AzureBlobSessionPersistence}
 * for small key/value-shaped session payloads, with no per-account container
 * to manage. All entities share one partition (`session`); the session id is
 * the row key.
 */
final class AzureTableSessionPersistence implements SessionPersistenceInterface
{
    private const string PARTITION_KEY = 'session';

    private bool $tableEnsured = false;

    public function __construct(
        private readonly AzureTableClient $client,
        private readonly string $table = 'sessions',
    ) {
    }

    #[\Override]
    public function load(string $sid): ?array
    {
        $entity = $this->client->get($this->table, self::PARTITION_KEY, $sid);
        if ($entity === null || !isset($entity['Data']) || !is_string($entity['Data'])) {
            return null;
        }

        try {
            $decoded = json_decode($entity['Data'], true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $data */
    #[\Override]
    public function save(string $sid, array $data): void
    {
        $this->ensureTable();
        $this->client->upsert($this->table, self::PARTITION_KEY, $sid, [
            'Data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }

    #[\Override]
    public function delete(string $sid): void
    {
        $this->client->delete($this->table, self::PARTITION_KEY, $sid);
    }

    private function ensureTable(): void
    {
        if (!$this->tableEnsured) {
            $this->client->ensureTableExists($this->table);
            $this->tableEnsured = true;
        }
    }
}
