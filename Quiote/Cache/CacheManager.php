<?php
namespace Quiote\Cache;

use Quiote\Config\Config;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * The framework's process-wide PSR-16 cache, and the namespace versioning that
 * cached output is invalidated through.
 *
 * Entirely static, with no instance to inject: {@see getCache()} returns the
 * shared {@see CacheInterface}, built once per process from
 * `core.cache_backend` -- APCu when the extension is usable, Redis through
 * `core.redis_dsn`, otherwise a filesystem pool under `core.cache_dir`.
 * {@see setCache()} installs any other PSR-16 implementation in its place, and
 * {@see getBackend()} reports which one is in force. Compose keys with
 * {@see key()} rather than by concatenation: PSR-16 reserves characters --
 * colon among them -- that symfony/cache refuses.
 *
 * Invalidation never deletes anything. Action, view and slot cache keys embed
 * the current version of a namespace, so bumping that version
 * ({@see invalidateModule()}, {@see invalidateAction()},
 * {@see invalidateSlotTag()}, or {@see bumpNamespace()} directly) makes every
 * key written under the old version unreachable and leaves the backend to evict
 * the orphans in its own time. Versions are derived from the clock rather than
 * counted, so a version key evicted under memory pressure reseeds above every
 * version that namespace has already issued instead of replaying old ones.
 *
 * Versions are memoized for the duration of a request.
 * {@see resetRequestState()} drops that memo at the request boundary, which is
 * what lets a persistent worker observe invalidations performed by another
 * request or another process. {@see reset()} is the heavier, test-oriented
 * clear: it drops the instance, the memo and the recorded backend name, and
 * purges the filesystem pool's directory.
 */
class CacheManager
{
    /** @var CacheInterface|null */
    private static ?CacheInterface $instance = null;
    /**
     * In-memory cache of namespace versions for the current request. Dropped at
     * the request boundary by {@see resetRequestState()}, which Context::reset()
     * calls -- it must be, or it is a per-process memo rather than a per-request
     * one: a version bumped by another worker process (or by another request in
     * this one) would never be re-read, so invalidateModule()/invalidateAction()/
     * invalidateSlotTag() would silently stop invalidating anything for the rest
     * of the process lifetime while it kept serving cached output under the old
     * version.
     * @var array<string, int>
     */
    private static array $namespaceVersions = [];
    /** selected backend name (filesystem|apcu|custom) */
    private static string $backend = 'filesystem';

    /**
     * The process-wide PSR-16 cache, built on first use from `core.cache_backend`.
     *
     * The instance is a static memo held for the lifetime of the process, so the
     * backend choice is made once: `apcu` when the extension is loaded and enabled,
     * `redis` via `core.redis_dsn`, otherwise a filesystem pool under
     * `core.cache_dir` (falling back to a `quiote_cache` directory in the system
     * temp dir when that setting is empty). An `apcu` request on a host without a
     * usable APCu silently falls through to the filesystem pool.
     *
     * @throws RuntimeException If the backend is `redis` and no Redis client
     *                          (ext-redis, ext-relay, predis/predis) is installed.
     */
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

    /**
     * Installs a cache instance process-wide, replacing whatever {@see getCache()}
     * would otherwise build, and records $backendName as the value
     * {@see getBackend()} reports.
     *
     * The override is static state and outlives the request that set it; it stays
     * in force until another call replaces it or {@see reset()} drops it.
     */
    public static function setCache(CacheInterface $cache, string $backendName = 'custom'): void
    { self::$instance = $cache; self::$backend = $backendName; }

    /**
     * Request-boundary clear for a persistent worker: drops the per-request
     * namespace-version memo so the next request re-reads versions from the
     * shared backend and therefore observes invalidations performed elsewhere.
     *
     * Deliberately narrower than {@see reset()}: the configured cache instance
     * and the selected backend survive (rebuilding the pool per request would
     * throw away exactly the connection reuse worker mode exists for), and
     * nothing on disk is touched.
     *
     * @return     void
     * @since      3.1.1
     */
    public static function resetRequestState(): void
    {
        self::$namespaceVersions = [];
    }

    /**
     * Drops all process-wide cache state and purges the filesystem pool's directory.
     *
     * Clears the memoized instance, the namespace-version memo and the recorded
     * backend name (back to `filesystem`), so the next {@see getCache()} rebuilds
     * from configuration. The on-disk purge is best effort: a path that vanishes
     * mid-sweep is logged at debug and the sweep continues, and any failure to
     * locate or traverse the directory at all is ignored — the in-memory reset has
     * already happened either way.
     *
     * Intended for test isolation and reconfiguration, not the request path; see
     * {@see resetRequestState()} for the per-request boundary.
     */
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
                        try {
                            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
                        } catch(\Throwable $e) {
                            // A path that vanished between listing and removal is the ordinary race
                            // when two workers clear the same directory; the sweep continues.
                            \Quiote\Logging\Log::create(self::class)->debug(
                                '[CacheManager] could not remove "' . $f->getPathname() . '" while clearing: '
                                . $e->getMessage()
                            );
                        }
                    }
                }
            } catch(\Throwable) { /* ignore purge errors */ }
    }

    /**
     * The name of the backend currently in use process-wide: `filesystem`, `apcu`,
     * `redis`, or whatever name {@see setCache()} was given.
     *
     * Reports `filesystem` until the cache is first built or overridden, since the
     * backend is only decided when {@see getCache()} runs.
     */
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

    /**
     * A namespace version derived from the clock (milliseconds), used both to seed a
     * namespace and as the floor for every bump.
     *
     * Deliberately not a counter starting at 1. The version lives in the same backend as
     * the data it guards, and that backend may evict it -- APCu under memory pressure,
     * Redis under a `maxmemory` policy. A counter would then restart and re-issue version
     * numbers the namespace has already used, while the entries written under those
     * numbers are separate keys that can easily still be live: content an invalidation
     * had already retired would come back.
     *
     * Tying both the seed and the bump floor to the clock removes that class of collision
     * entirely -- a version is never below the clock reading at the moment it was issued,
     * so a reseed after eviction always lands at or above every version the namespace has
     * ever used. {@see bumpNamespace()} keeps the strict-increase guarantee on top.
     */
    private static function freshNamespaceVersion(): int
    {
        // Wall-clock, not monotonic: this value is written to a backend other processes
        // read too, and a monotonic reading is only meaningful within the process that
        // took it.
        return (int) (\Quiote\Support\Clock\Clock::instance()->microtime() * 1000);
    }

    /**
     * The current version of a cache namespace, seeding one if the backend has none.
     *
     * Read through a per-request memo (see {@see resetRequestState()}); on a miss the
     * version is fetched from the cache backend, and if it is absent or not a
     * positive integer — the namespace is new, or the backend evicted the version
     * key — a fresh clock-derived version is generated and written back, which
     * invalidates every entry previously stored under that namespace.
     */
    public static function getNamespaceVersion(string $namespace): int
    {
        if (!isset(self::$namespaceVersions[$namespace])) {
            $cache = self::getCache();
            $ver = $cache->get(self::versionCacheKey($namespace));
            if (!is_int($ver) || $ver < 1) {
                $ver = self::freshNamespaceVersion();
                $cache->set(self::versionCacheKey($namespace), $ver);
            }
            self::$namespaceVersions[$namespace] = $ver;
        }
        return self::$namespaceVersions[$namespace];
    }

    /**
     * Invalidate a namespace by moving its version forward.
     *
     * The new version is the later of "one past the current one" and the current clock
     * reading. The +1 keeps the strict increase a bump has to guarantee even when several
     * bumps land inside the same millisecond; the clock floor is what keeps the version
     * from drifting below a future reseed after an eviction (see
     * {@see freshNamespaceVersion()}).
     */
    public static function bumpNamespace(string $namespace): int
    {
        $cache = self::getCache();
        $ver = max(self::getNamespaceVersion($namespace) + 1, self::freshNamespaceVersion());
        $cache->set(self::versionCacheKey($namespace), $ver);
        self::$namespaceVersions[$namespace] = $ver;
        return $ver;
    }

    /**
     * Invalidates every action/view cache entry belonging to a module by bumping
     * that module's namespace version.
     *
     * Nothing is deleted: the entries stay in the backend until it evicts them, but
     * their keys are no longer reachable. The bump is written to the shared backend
     * and to this process's namespace-version memo.
     */
    public static function invalidateModule(string $moduleName): void
    { self::bumpNamespace(self::key('avmod', $moduleName)); }

    /**
     * Invalidates the cache entries of a single action by bumping a namespace
     * combining the module and action names.
     *
     * Narrower than {@see invalidateModule()}, which retires the whole module: the
     * two use different namespaces, so bumping one does not affect the other.
     */
    public static function invalidateAction(string $moduleName, string $actionName): void
    { self::bumpNamespace(self::key('avact', $moduleName, $actionName)); }

    /**
     * Invalidates every slot cache entry carrying $tag by bumping the tag's
     * namespace version.
     *
     * The tag is normalized through {@see slotTagNamespace()}, so tags differing
     * only in characters that normalization replaces share one namespace and are
     * invalidated together.
     */
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
