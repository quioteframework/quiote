<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;
use Quiote\Config\Config;
use Quiote\DI\Container;
use Quiote\Plugin\PluginManager;
use Quiote\Queue\QueueDriverRegistry;
use Quiote\Queue\Redis\QueueRedisPlugin;
use Quiote\Queue\Redis\RedisQueueDriver;

/**
 * QueueRedisPlugin::register() -- config defaults, the `redis` driver alias
 * and the container service factory. Predis connects lazily, so resolving the
 * driver here builds a client without reaching a server; RedisQueueDriverTest
 * exercises the driver against a real one.
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

    /**
     * An application-set `queue.redis.dsn` has to win over the plugin's
     * default, since configDefault() is set-if-absent.
     */
    public function testApplicationConfigWinsOverTheDefaults(): void
    {
        Config::set('queue.redis.dsn', 'redis://redis.internal:6380');
        Config::set('queue.redis.prefix', 'app_jobs');

        PluginManager::add(new QueueRedisPlugin());
        PluginManager::bootFromConfig();

        $this->assertSame('redis://redis.internal:6380', Config::getString('queue.redis.dsn'));
        $this->assertSame('app_jobs', Config::getString('queue.redis.prefix'));
    }

    public function testTheContainerResolvesTheDriverFromConfig(): void
    {
        Config::set('queue.redis.dsn', 'redis://redis.internal:6380');
        Config::set('queue.redis.prefix', 'app_jobs');

        PluginManager::add(new QueueRedisPlugin());
        PluginManager::bootFromConfig();

        $container = new Container();
        PluginManager::configureContainer($container);
        $driver = $container->get(RedisQueueDriver::class);

        $this->assertInstanceOf(RedisQueueDriver::class, $driver);
        $this->assertSame('app_jobs', (new ReflectionProperty(RedisQueueDriver::class, 'prefix'))->getValue($driver));

        $client = (new ReflectionProperty(RedisQueueDriver::class, 'redis'))->getValue($driver);
        $this->assertInstanceOf(ClientInterface::class, $client);
        $this->assertSame('redis.internal', $client->getConnection()->getParameters()->host);
    }

    public function testTheDriverIsASingletonSoWorkersShareOneConnection(): void
    {
        PluginManager::add(new QueueRedisPlugin());
        PluginManager::bootFromConfig();

        $container = new Container();
        PluginManager::configureContainer($container);

        $this->assertSame($container->get(RedisQueueDriver::class), $container->get(RedisQueueDriver::class));
    }
}
