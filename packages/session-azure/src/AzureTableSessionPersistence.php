<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

use Quiote\Session\SessionCodec;
use Quiote\Session\SessionCodecInterface;
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
        private readonly SessionCodecInterface $codec = new SessionCodec(preferBinary: false),
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * Reads the entity whose row key is the session id from the shared
     * partition and decodes its `Data` property. An absent entity, an entity
     * without a `Data` property, or one whose `Data` is not a string all read
     * as an unknown session.
     *
     * @return array<string, mixed>|null
     */
    #[\Override]
    public function load(string $sid): ?array
    {
        $entity = $this->client->get($this->table, self::PARTITION_KEY, $sid);
        if ($entity === null || !isset($entity['Data']) || !is_string($entity['Data'])) {
            return null;
        }

        return $this->codec->decode($entity['Data']);
    }

    /** @param array<string, mixed> $data */
    #[\Override]
    public function save(string $sid, array $data): void
    {
        $this->ensureTable();
        $this->client->upsert($this->table, self::PARTITION_KEY, $sid, [
            'Data' => $this->codec->encode($data),
        ]);
    }

    /**
     * {@inheritDoc}
     *
     * Deletes the entity unconditionally; the table is not created for a
     * delete, and an entity that is not there is not an error.
     *
     * @throws AzureStorageException If Azure answers with anything other than
     *                               success or 404.
     */
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
