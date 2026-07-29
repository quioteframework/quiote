<?php
namespace Quiote\Cache;

use Quiote\Config\Config;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Psr16Cache;

class CacheManager
{
    /** @var CacheInterface|null */
    private static ?CacheInterface $instance = null;
    /**
     * in-memory cache of namespace versions for current request
     * @var array<string, int>
     */
    private static array $namespaceVersions = [];
    /** selected backend name (filesystem|apcu|custom) */
    private static string $backend = 'filesystem';

    public static function getCache(): CacheInterface
    {
        if (self::$instance === null) {
            $backendCfg = Config::getString('core.cache_backend', 'filesystem');
            if ($backendCfg === 'apcu' && self::apcuAvailable()) {
                $pool = new ApcuAdapter();
                self::$backend = 'apcu';
            } elseif ($backendCfg === 'redis') {
                $pool = new RedisAdapter(self::createRedisConnection());
                self::$backend = 'redis';
            } else {
                $baseDir = Config::getString('core.cache_dir');
                if(empty($baseDir)) {
                    $baseDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'quiote_cache';
                }
                $dir = $baseDir . DIRECTORY_SEPARATOR . 'psr-cache';
                $pool = new FilesystemAdapter('', 0, $dir);
                self::$backend = 'filesystem';
            }
            self::$instance = new Psr16Cache($pool);
        }
        return self::$instance;
    }

    public static function setCache(CacheInterface $cache, string $backendName = 'custom'): void
    { self::$instance = $cache; self::$backend = $backendName; }

    public static function reset(): void
    {
        self::$instance = null; self::$namespaceVersions = []; self::$backend = 'filesystem';
        // Best-effort purge of filesystem cache directory to isolate test runs (slot/action caches)
            try {
                $dir = \Quiote\Config\Config::getString('core.cache_dir');
                if(empty($dir)) {
                    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'quiote_cache';
                }
                $psrDir = $dir . DIRECTORY_SEPARATOR . 'psr-cache';
                if(is_dir($psrDir)) {
                    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($psrDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                    foreach($it as $f) {
                        if (!$f instanceof \SplFileInfo) {
                            continue;
                        }
                        try { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); } catch(\Throwable) {}
                    }
                }
            } catch(\Throwable) { /* ignore purge errors */ }
    }

    public static function getBackend(): string { return self::$backend; }

    private static function apcuAvailable(): bool
    { return function_exists('apcu_enabled') && apcu_enabled(); }

    /** @return \Redis|\RedisArray|\RedisCluster|\Predis\ClientInterface|\Relay\Relay|\Relay\Cluster */
    private static function createRedisConnection(): object
    {
        if (
            !extension_loaded('redis')
            && !extension_loaded('relay')
            && !interface_exists(\Predis\ClientInterface::class)
        ) {
            throw new RuntimeException(
                'core.cache_backend is "redis" but no Redis client is available. Install ext-redis, ext-relay, or predis/predis (e.g. `composer require predis/predis`).',
            );
        }

        $dsn = Config::getString('core.redis_dsn', 'redis://127.0.0.1:6379');
        /** @var \Redis|\RedisArray|\RedisCluster|\Predis\ClientInterface|\Relay\Relay|\Relay\Cluster $connection */
        $connection = RedisAdapter::createConnection($dsn);
        return $connection;
    }

    /**
     * PSR-16 §1.3 reserves `{}()/\@:` in cache keys: a conforming
     * implementation may reject any key containing one, and symfony/cache does
     * — either by throwing (`zend.assertions=1`) or, with assertions compiled
     * out in production, by letting a key through that another backend would
     * refuse. Colon is the obvious separator to reach for and is exactly the
     * one that is off-limits, so every framework cache key is composed here
     * instead of by inline concatenation.
     * Reserved characters inside a part are replaced rather than escaped, so
     * two parts differing only in reserved characters map to the same key —
     * fine for the module/action/tag/version parts this is used with, but not
     * a general-purpose escaping scheme.
     * @param      string ...$parts The key segments, outermost namespace first.
     * @return     string A key legal for any PSR-16 implementation.
     * @since      3.0.2
     */
    public static function key(string ...$parts): string
    {
        $safe = array_map(
            static fn(string $part): string => str_replace(['{', '}', '(', ')', '/', '\\', '@', ':'], '_', $part),
            $parts,
        );
        return implode('.', $safe);
    }

    private static function versionCacheKey(string $namespace): string
    { return self::key('nsver', $namespace); }

    public static function getNamespaceVersion(string $namespace): int
    {
        if (!isset(self::$namespaceVersions[$namespace])) {
            $cache = self::getCache();
            $ver = $cache->get(self::versionCacheKey($namespace));
            if (!is_int($ver) || $ver < 1) {
                $ver = 1;
                $cache->set(self::versionCacheKey($namespace), $ver);
            }
            self::$namespaceVersions[$namespace] = $ver;
        }
        return self::$namespaceVersions[$namespace];
    }

    public static function bumpNamespace(string $namespace): int
    {
        $cache = self::getCache();
        $ver = self::getNamespaceVersion($namespace) + 1;
        $cache->set(self::versionCacheKey($namespace), $ver);
        self::$namespaceVersions[$namespace] = $ver;
        return $ver;
    }

    // Invalidate all action/view cache entries for a module by bumping module namespace version.
    public static function invalidateModule(string $moduleName): void
    { self::bumpNamespace(self::key('avmod', $moduleName)); }

    // Future extension: invalidate a single action by a dedicated namespace combining module+action.
    public static function invalidateAction(string $moduleName, string $actionName): void
    { self::bumpNamespace(self::key('avact', $moduleName, $actionName)); }

    // Invalidate all slot cache entries referencing a given tag
    public static function invalidateSlotTag(string $tag): void
    { self::bumpNamespace(self::slotTagNamespace($tag)); }

    /**
     * The namespace whose version invalidates every slot cache entry carrying
     * `$tag`. Shared with SlotDispatcher, which composes the same namespace
     * when it builds a slot's cache key — the two must agree exactly or a
     * tag bump invalidates nothing.
     * @param      string $tag The slot cache tag.
     * @return     string The namespace name to pass to {@see getNamespaceVersion()}.
     * @since      3.0.2
     */
    public static function slotTagNamespace(string $tag): string
    {
        $normalized = preg_replace('/[^a-z0-9_-]/i', '_', $tag) ?? $tag;
        return self::key('slot_tag', $normalized);
    }
}
