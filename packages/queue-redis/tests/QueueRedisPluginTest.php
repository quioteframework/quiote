<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Plugin\PluginManager;
use Quiote\Queue\QueueDriverRegistry;
use Quiote\Queue\Redis\QueueRedisPlugin;
use Quiote\Queue\Redis\RedisQueueDriver;

/**
 * QueueRedisPlugin::register() -- config defaults and the `redis` driver
 * alias. Does not exercise the container service factory (it needs a real
 * Redis connection — see RedisQueueDriverTest instead).
 */
final class QueueRedisPluginTest extends TestCase
{
    #[Before]
    #[After]
    public function resetState(): void
    {
        PluginManager::reset();
        QueueDriverRegistry::reset();
        Config::remove('queue.redis.dsn');
        Config::remove('queue.redis.prefix');
    }

    public function testRegistersDefaultConfig(): void
    {
        PluginManager::add(new QueueRedisPlugin());
        PluginManager::bootFromConfig();

        $this->assertSame('redis://127.0.0.1:6379', Config::getString('queue.redis.dsn'));
        $this->assertSame('quiote_queue', Config::getString('queue.redis.prefix'));
    }

    public function testRegistersTheRedisDriverAlias(): void
    {
        PluginManager::add(new QueueRedisPlugin());
        PluginManager::bootFromConfig();

        $this->assertTrue(QueueDriverRegistry::has('redis'));
        $this->assertSame(RedisQueueDriver::class, QueueDriverRegistry::resolve('redis'));
    }
}
