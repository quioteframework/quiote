<?php

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Quiote\Cache\CacheManager;
use Quiote\Config\Config;
use Quiote\Test\Cache\Psr16KeyRecordingCache;
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

    public function testKeyJoinsPartsWithALegalSeparator(): void
    {
        $this->assertSame('nsver.avmod.blog', CacheManager::key('nsver', 'avmod', 'blog'));
    }

    public function testKeyReplacesEveryCharacterPsr16Reserves(): void
    {
        $key = CacheManager::key('ns', 'a:b{c}d(e)f/g\\h@i');

        $this->assertSame('ns.a_b_c_d_e_f_g_h_i', $key);
        $this->assertFalse(strpbrk($key, '{}()/\\@:'), 'key() must not emit a reserved character');
    }

    /**
     * The namespace-version keys used to be built as 'nsver:' . $namespace.
     * PSR-16 §1.3 reserves ':', and symfony/cache rejects it — but only behind
     * an assert(), so the failure appears with zend.assertions=1 (development)
     * and vanishes with -1 (production), which is how this survived so long.
     */
    public function testNamespaceVersionKeysAreLegalUnderPsr16(): void
    {
        $spy = new Psr16KeyRecordingCache();
        CacheManager::setCache($spy, 'spy');

        CacheManager::getNamespaceVersion(CacheManager::key('avmod', 'blog'));
        CacheManager::bumpNamespace(CacheManager::key('avmod', 'blog'));

        $this->assertNotEmpty($spy->recordedKeys());
        $this->assertSame([], $spy->illegalKeys());
    }

    public function testInvalidationHelpersProduceLegalKeys(): void
    {
        $spy = new Psr16KeyRecordingCache();
        CacheManager::setCache($spy, 'spy');

        CacheManager::invalidateModule('blog');
        CacheManager::invalidateAction('blog', 'Index');
        // A tag an app is free to write with a colon in it must not leak one
        // through into the key.
        CacheManager::invalidateSlotTag('post:42');

        $this->assertNotEmpty($spy->recordedKeys());
        $this->assertSame([], $spy->illegalKeys());
    }

    public function testSlotTagNamespaceIsStableForTheSameTag(): void
    {
        $this->assertSame(
            CacheManager::slotTagNamespace('post:42'),
            CacheManager::slotTagNamespace('post:42'),
        );
        $this->assertFalse(strpbrk(CacheManager::slotTagNamespace('post:42'), '{}()/\\@:'));
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
