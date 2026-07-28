<?php

namespace Quiote\Security\RateLimit;

use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginRegistrar;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * Registers {@see RateLimitMiddleware} through the generic plugin seam,
 * opt-in via `ratelimit.http.enabled`. Binds a default {@see StorageInterface}
 * (set-if-absent) selected by `ratelimit.storage`: `memory` (default,
 * {@see InMemoryStorage}) or `redis` ({@see CacheStorage} wrapping a
 * {@see RedisAdapter} built from `ratelimit.redis.dsn`). An app can still bind
 * {@see PdoRateLimiterStorage} directly instead, for state that survives
 * across worker/process restarts without a Redis dependency.
 */
#[PluginAttribute(name: 'quiote/ratelimit')]
final class RateLimitPlugin implements PluginInterface
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->configDefault('ratelimit.http.enabled', false);
        $registrar->configDefault('ratelimit.http.max_requests', 60);
        $registrar->configDefault('ratelimit.http.window', '1 minute');
        $registrar->configDefault('ratelimit.http.policy', 'sliding_window');
        $registrar->configDefault('ratelimit.http.trust_forwarded_for', false);
        $registrar->configDefault('ratelimit.storage', 'memory');
        $registrar->configDefault('ratelimit.redis.dsn', 'redis://127.0.0.1:6379');

        $registrar->service(StorageInterface::class, static fn() => self::makeStorage());

        $registrar->attributedMiddleware(
            RateLimitMiddleware::class,
            static function (Context $context): RateLimitMiddleware {
                $storage = $context->getContainer()->get(StorageInterface::class);
                if (!$storage instanceof StorageInterface) {
                    throw new \RuntimeException(sprintf(
                        'The "%s" service must resolve to a %s, got %s.',
                        StorageInterface::class,
                        StorageInterface::class,
                        get_debug_type($storage),
                    ));
                }
                return new RateLimitMiddleware($storage);
            },
        );
    }

    private static function makeStorage(): StorageInterface
    {
        $backend = Config::getString('ratelimit.storage', 'memory');

        if ($backend !== 'redis') {
            return new InMemoryStorage();
        }

        if (!extension_loaded('redis') && !extension_loaded('relay') && !interface_exists(\Predis\ClientInterface::class)) {
            throw new \RuntimeException(
                'ratelimit.storage is "redis" but no Redis client is available. Install ext-redis, ext-relay, or predis/predis (e.g. `composer require predis/predis`).',
            );
        }

        $dsn = Config::getString('ratelimit.redis.dsn', 'redis://127.0.0.1:6379');
        $connection = RedisAdapter::createConnection($dsn);

        return new CacheStorage(new RedisAdapter($connection));
    }
}
