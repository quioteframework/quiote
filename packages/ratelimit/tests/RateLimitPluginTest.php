<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\DI\Container;
use Quiote\Plugin\PluginManager;
use Quiote\Security\RateLimit\RateLimitPlugin;
use Quiote\Test\Redis\RedisContainers;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * RateLimitPlugin::register() -- config defaults and the memory/redis
 * StorageInterface selection.
 */
final class RateLimitPluginTest extends TestCase
{
    #[Before]
    #[After]
    public function resetState(): void
    {
        PluginManager::reset();
        Config::remove('ratelimit.http.enabled');
        Config::remove('ratelimit.http.max_requests');
        Config::remove('ratelimit.http.window');
        Config::remove('ratelimit.http.policy');
        Config::remove('ratelimit.http.trust_forwarded_for');
        Config::remove('ratelimit.storage');
        Config::remove('ratelimit.redis.dsn');
    }

    public function testRegistersDefaultConfig(): void
    {
        PluginManager::add(new RateLimitPlugin());
        PluginManager::bootFromConfig();

        $this->assertFalse(Config::get('ratelimit.http.enabled'));
        $this->assertSame('memory', Config::getString('ratelimit.storage'));
        $this->assertSame('redis://127.0.0.1:6379', Config::getString('ratelimit.redis.dsn'));
    }

    public function testDefaultStorageServiceIsInMemory(): void
    {
        PluginManager::add(new RateLimitPlugin());
        PluginManager::bootFromConfig();

        $container = new Container();
        PluginManager::configureContainer($container);

        $this->assertInstanceOf(InMemoryStorage::class, $container->get(StorageInterface::class));
    }

    public function testMissingRedisClientThrowsWhenStorageIsRedis(): void
    {
        if (extension_loaded('redis') || extension_loaded('relay') || interface_exists(\Predis\ClientInterface::class)) {
            $this->markTestSkipped('A Redis client is installed in this environment; the missing-client guard cannot be exercised here.');
        }

        Config::set('ratelimit.storage', 'redis');
        PluginManager::add(new RateLimitPlugin());
        PluginManager::bootFromConfig();

        $container = new Container();
        PluginManager::configureContainer($container);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no Redis client is available');

        $container->get(StorageInterface::class);
    }

    #[Group('integration')]
    public function testRedisStorageServiceRoundTripsThroughARealRedis(): void
    {
        if (!RedisContainers::dockerAvailable()) {
            $this->markTestSkipped('Docker is not available for integration tests');
        }

        Config::set('ratelimit.storage', 'redis');
        Config::set('ratelimit.redis.dsn', RedisContainers::dsn());
        PluginManager::add(new RateLimitPlugin());
        PluginManager::bootFromConfig();

        $container = new Container();
        PluginManager::configureContainer($container);

        $this->assertInstanceOf(CacheStorage::class, $container->get(StorageInterface::class));
    }
}
