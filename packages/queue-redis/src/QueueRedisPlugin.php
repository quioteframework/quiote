<?php

declare(strict_types=1);

namespace Quiote\Queue\Redis;

use Predis\Client;
use Quiote\Config\Config;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginRegistrar;
use Quiote\Queue\QueueDriverRegistry;
use Quiote\DI\Container;

/**
 * Registers the `redis` queue driver alias and publishes `queue.redis.*`
 * config defaults. Unlike {@see \Quiote\Queue\Db\QueueDbPlugin}, the Redis
 * connection is self-contained (built straight from a DSN, no dependency on
 * the current {@see \Quiote\Context}'s `DatabaseManager`).
 */
#[PluginAttribute(name: 'quiote/queue-redis')]
final class QueueRedisPlugin implements PluginInterface
{
    /**
     * Publishes the `queue.redis.*` defaults and registers the `redis` driver.
     *
     * Adds the `redis` alias to {@see QueueDriverRegistry} and binds
     * {@see RedisQueueDriver} as a singleton whose factory builds a Predis
     * client from `queue.redis.dsn`. The client is constructed lazily, when the
     * driver is first resolved, not while plugins are registering.
     */
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->configDefault('queue.redis.dsn', 'redis://127.0.0.1:6379');
        $registrar->configDefault('queue.redis.prefix', 'quiote_queue');

        QueueDriverRegistry::register('redis', RedisQueueDriver::class);
        $registrar->stateReset('queue-driver-registry', static fn() => QueueDriverRegistry::reset());

        $registrar->service(
            RedisQueueDriver::class,
            static fn() => new RedisQueueDriver(
                new Client(Config::getString('queue.redis.dsn', 'redis://127.0.0.1:6379')),
                Config::getString('queue.redis.prefix', 'quiote_queue'),
            ),
            Container::SCOPE_SINGLETON,
        );
    }
}
