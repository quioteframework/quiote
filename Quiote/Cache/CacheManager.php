<?php
namespace Quiote\Cache;

use Quiote\Config\Config;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Psr16Cache;

class CacheManager
{
    /** @var CacheInterface|null */
    private static ?CacheInterface $instance = null;
    /** in-memory cache of namespace versions for current request */
    private static array $namespaceVersions = [];
    /** selected backend name (filesystem|apcu|custom) */
    private static string $backend = 'filesystem';

    public static function getCache(): CacheInterface
    {
        if (self::$instance === null) {
            $backendCfg = Config::get('core.cache_backend');
            if ($backendCfg === 'apcu' && self::apcuAvailable()) {
                $pool = new ApcuAdapter();
                self::$backend = 'apcu';
            } else {
                $baseDir = Config::get('core.cache_dir');
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
                $dir = \Quiote\Config\Config::get('core.cache_dir');
                if(empty($dir)) {
                    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'quiote_cache';
                }
                $psrDir = $dir . DIRECTORY_SEPARATOR . 'psr-cache';
                if(is_dir($psrDir)) {
                    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($psrDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                    foreach($it as $f) {
                        try { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); } catch(\Throwable) {}
                    }
                }
            } catch(\Throwable) { /* ignore purge errors */ }
    }

    public static function getBackend(): string { return self::$backend; }

    private static function apcuAvailable(): bool
    { return function_exists('apcu_enabled') && apcu_enabled(); }

    private static function versionCacheKey(string $namespace): string
    { return 'nsver:' . $namespace; }

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
    { self::bumpNamespace('avmod:' . $moduleName); }

    // Future extension: invalidate a single action by a dedicated namespace combining module+action.
    public static function invalidateAction(string $moduleName, string $actionName): void
    { self::bumpNamespace('avact:' . $moduleName . ':' . $actionName); }

    // Invalidate all slot cache entries referencing a given tag
    public static function invalidateSlotTag(string $tag): void
    { self::bumpNamespace('slot_tag:' . preg_replace('/[^a-z0-9:_-]/i','_', $tag)); }
}
