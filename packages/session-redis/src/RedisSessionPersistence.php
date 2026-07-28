<?php

declare(strict_types=1);

namespace Quiote\Session\Redis;

use JsonException;
use Predis\ClientInterface;
use Quiote\Session\SessionPersistenceInterface;
use Throwable;

/**
 * Redis-backed {@see SessionPersistenceInterface} for
 * {@see \Quiote\Session\SessionManager}. One string key per session id,
 * written with `SETEX` so Redis itself expires stale sessions — no GC pass
 * needed, unlike the PDO/file backends.
 */
final class RedisSessionPersistence implements SessionPersistenceInterface
{
    public function __construct(
        private readonly ClientInterface $redis,
        private readonly string $prefix = 'session:',
        private readonly int $ttl = 1440,
    ) {
    }

    #[\Override]
    public function load(string $sid): ?array
    {
        $payload = $this->redis->get($this->key($sid));

        if (!is_string($payload) || $payload === '') {
            return null;
        }

        return $this->decode($payload);
    }

    /** @param array<string, mixed> $data */
    #[\Override]
    public function save(string $sid, array $data): void
    {
        $this->redis->setex($this->key($sid), $this->ttl, $this->encode($data));
    }

    #[\Override]
    public function delete(string $sid): void
    {
        $this->redis->del([$this->key($sid)]);
    }

    private function key(string $sid): string
    {
        return $this->prefix . $sid;
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        if (function_exists('igbinary_serialize')) {
            try {
                $serialized = igbinary_serialize($data);
                if (is_string($serialized)) {
                    return $serialized;
                }
            } catch (Throwable) {
                // fall through to JSON
            }
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed>|null */
    private function decode(string $payload): ?array
    {
        if (function_exists('igbinary_unserialize') && !str_starts_with($payload, '{') && !str_starts_with($payload, '[')) {
            try {
                $decoded = igbinary_unserialize($payload);
                return is_array($decoded) ? $this->stringKeyed($decoded) : null;
            } catch (Throwable) {
                return null;
            }
        }

        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $this->stringKeyed($decoded) : null;
    }

    /**
     * @param array<mixed, mixed> $data
     * @return array<string, mixed>
     */
    private function stringKeyed(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $result[(string) $key] = $value;
        }
        return $result;
    }
}
