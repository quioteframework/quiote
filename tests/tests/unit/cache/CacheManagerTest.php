<?php

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Quiote\Cache\CacheManager;
use Quiote\Config\Config;
use Quiote\Test\Redis\RedisContainers;

final class CacheManagerTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        CacheManager::reset();
        Config::remove('core.cache_backend');
        Config::remove('core.redis_dsn');
    }

    #[\Override]
    protected function tearDown(): void
    {
        CacheManager::reset();
        Config::remove('core.cache_backend');
        Config::remove('core.redis_dsn');
    }

    public function testDefaultsToFilesystemBackend(): void
    {
        CacheManager::getCache();

        $this->assertSame('filesystem', CacheManager::getBackend());
    }

    public function testUnknownBackendFallsBackToFilesystem(): void
    {
        Config::set('core.cache_backend', 'not-a-real-backend');

        CacheManager::getCache();

        $this->assertSame('filesystem', CacheManager::getBackend());
    }

    public function testRedisBackendWithNoRedisClientAvailableThrows(): void
    {
        if (extension_loaded('redis') || extension_loaded('relay') || interface_exists(\Predis\ClientInterface::class)) {
            $this->markTestSkipped('A Redis client is installed in this environment; the missing-client guard cannot be exercised here.');
        }

        Config::set('core.cache_backend', 'redis');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no Redis client is available');

        CacheManager::getCache();
    }

    #[Group('integration')]
    public function testRedisBackendStoresAndRetrievesValues(): void
    {
        if (!RedisContainers::dockerAvailable()) {
            $this->markTestSkipped('Docker is not available for integration tests');
        }

        Config::set('core.cache_backend', 'redis');
        Config::set('core.redis_dsn', RedisContainers::dsn());

        $cache = CacheManager::getCache();

        $this->assertSame('redis', CacheManager::getBackend());

        $cache->set('cache-manager-test-key', 'hello');
        $this->assertSame('hello', $cache->get('cache-manager-test-key'));
    }
}
