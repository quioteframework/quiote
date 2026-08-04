<?php

declare(strict_types=1);

namespace Quiote\Storage\S3;

use Quiote\Session\SessionCodec;
use Quiote\Session\SessionCodecInterface;
use Quiote\Session\SessionPersistenceInterface;

/**
 * {@see SessionPersistenceInterface} storing one JSON object per session id
 * (key `<prefix><sid>.json`) in a single S3 bucket.
 */
final class S3SessionPersistence implements SessionPersistenceInterface
{
    public function __construct(
        private readonly S3Client $client,
        private readonly string $keyPrefix = 'sessions/',
        private readonly SessionCodecInterface $codec = new SessionCodec(preferBinary: false),
    ) {
    }

    #[\Override]
    public function load(string $sid): ?array
    {
        $payload = $this->client->get($this->key($sid));
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
            $this->key($sid),
            $this->codec->encode($data),
        );
    }

    #[\Override]
    public function delete(string $sid): void
    {
        $this->client->delete($this->key($sid));
    }

    private function key(string $sid): string
    {
        return "{$this->keyPrefix}{$sid}.json";
    }

}
