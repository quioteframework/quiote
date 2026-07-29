<?php

declare(strict_types=1);

namespace Quiote\Session\Redis;

use Predis\Client;
use Quiote\Context;
use Quiote\Session\SessionFactoryInterface;
use Quiote\Session\SessionPersistenceInterface;
use RuntimeException;

/**
 * `session` slot factory for {@see RedisSessionPersistence}.
 *
 * ```yaml
 * session:
 *   class: Quiote\Session\Redis\RedisSessionFactory
 *   params:
 *     dsn: 'redis://127.0.0.1:6379'
 *     prefix: 'session:'
 *     ttl: 1440
 * ```
 *
 * Redis expires the key itself, so `ttl` doubles as the session lifetime and
 * there is no garbage-collection pass to schedule.
 *
 * @since      3.0.0
 */
final class RedisSessionFactory implements SessionFactoryInterface
{
    public function createPersistence(Context $context, array $parameters): SessionPersistenceInterface
    {
        if (!class_exists(Client::class)) {
            throw new RuntimeException(
                'Redis-backed sessions need predis/predis. Run: composer require predis/predis',
            );
        }

        $dsn = $parameters['dsn'] ?? null;
        $prefix = $parameters['prefix'] ?? null;
        $ttl = $parameters['ttl'] ?? null;

        return new RedisSessionPersistence(
            new Client(is_string($dsn) && $dsn !== '' ? $dsn : 'redis://127.0.0.1:6379'),
            is_string($prefix) ? $prefix : 'session:',
            is_numeric($ttl) ? (int) $ttl : 1440,
        );
    }
}
