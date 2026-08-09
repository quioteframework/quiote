<?php

declare(strict_types=1);

namespace Quiote\Session\Redis;

use JsonException;
use Predis\ClientInterface;
use Quiote\Session\SessionCodec;
use Quiote\Session\SessionCodecInterface;
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
        private readonly SessionCodecInterface $codec = new SessionCodec(preferBinary: true),
    ) {
    }

    /**
     * Reads the session's Redis key and decodes it through the codec.
     *
     * Returns null when the key is missing or expired — Redis expires it on its
     * own, so an aged-out session is simply absent — and when the value is empty
     * or not a string.
     */
    #[\Override]
    public function load(string $sid): ?array
    {
        $payload = $this->redis->get($this->key($sid));

        if (!is_string($payload) || $payload === '') {
            return null;
        }

        return $this->codec->decode($payload);
    }

    /** @param array<string, mixed> $data */
    #[\Override]
    public function save(string $sid, array $data): void
    {
        $this->redis->setex($this->key($sid), $this->ttl, $this->codec->encode($data));
    }

    /**
     * Deletes the session's Redis key.
     *
     * Deleting a key that is absent or already expired is a no-op on Redis's
     * side, so this reports nothing back either way.
     */
    #[\Override]
    public function delete(string $sid): void
    {
        $this->redis->del([$this->key($sid)]);
    }

    private function key(string $sid): string
    {
        return $this->prefix . $sid;
    }

}
