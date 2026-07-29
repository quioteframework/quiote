<?php

declare(strict_types=1);

namespace Quiote\Storage\Gcs;

use JsonException;
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
    ) {
    }

    #[\Override]
    public function load(string $sid): ?array
    {
        $payload = $this->client->get($this->objectName($sid));
        if ($payload === null || $payload === '') {
            return null;
        }

        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return self::asSessionData($decoded);
    }

    /** @param array<string, mixed> $data */
    #[\Override]
    public function save(string $sid, array $data): void
    {
        $this->client->put(
            $this->objectName($sid),
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
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

    /**
     * Narrow a decoded payload to the string-keyed shape a session is. A JSON
     * list, or an igbinary payload holding one, decodes to integer keys: that is
     * not session data, and handing it back would make the caller's key lookups
     * silently miss.
     *
     * @return array<string, mixed>|null
     */
    private static function asSessionData(mixed $decoded): ?array
    {
        if (!is_array($decoded)) {
            return null;
        }

        $result = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                return null;
            }
            $result[$key] = $value;
        }

        return $result;
    }

}
