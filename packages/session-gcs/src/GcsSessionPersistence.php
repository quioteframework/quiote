<?php

declare(strict_types=1);

namespace Quiote\Storage\Gcs;

use Quiote\Session\SessionCodec;
use Quiote\Session\SessionCodecInterface;
use Quiote\Session\SessionPersistenceInterface;

/**
 * {@see SessionPersistenceInterface} storing one JSON object per session id
 * (name `<prefix><sid>.json`) in a single GCS bucket.
 */
final class GcsSessionPersistence implements SessionPersistenceInterface
{
    public function __construct(
        private readonly GcsClient $client,
        private readonly string $objectPrefix = 'sessions/',
        private readonly SessionCodecInterface $codec = new SessionCodec(preferBinary: false),
    ) {
    }

    #[\Override]
    public function load(string $sid): ?array
    {
        $payload = $this->client->get($this->objectName($sid));
        if ($payload === null || $payload === '') {
            return null;
        }

        return $this->codec->decode($payload);
    }

    /** @param array<string, mixed> $data */
    #[\Override]
    public function save(string $sid, array $data): void
    {
        $this->client->put(
            $this->objectName($sid),
            $this->codec->encode($data),
        );
    }

    #[\Override]
    public function delete(string $sid): void
    {
        $this->client->delete($this->objectName($sid));
    }

    private function objectName(string $sid): string
    {
        return "{$this->objectPrefix}{$sid}.json";
    }

}
