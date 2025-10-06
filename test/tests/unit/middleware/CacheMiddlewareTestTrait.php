<?php

use Agavi\Cache\CacheManager;
use Agavi\Config\AgaviConfig;

trait CacheMiddlewareTestTrait
{
    private bool $cacheEnabledWasDefined = false;
    private bool $useCacheWasDefined = false;
    private bool $cacheDirWasDefined = false;
    private $previousCacheEnabled;
    private $previousUseCache;
    private $previousCacheDir;
    private string $cacheTestDir = '';

    protected function bootstrapCache(string $suffix): void
    {
        $this->cacheTestDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $suffix;

        $this->cacheEnabledWasDefined = AgaviConfig::has('core.cache_enabled');
        if($this->cacheEnabledWasDefined) {
            $this->previousCacheEnabled = AgaviConfig::get('core.cache_enabled');
        }
        AgaviConfig::set('core.cache_enabled', true);

        $this->useCacheWasDefined = AgaviConfig::has('core.use_cache');
        if($this->useCacheWasDefined) {
            $this->previousUseCache = AgaviConfig::get('core.use_cache');
        }
        AgaviConfig::set('core.use_cache', true);

        $this->cacheDirWasDefined = AgaviConfig::has('core.cache_dir');
        if($this->cacheDirWasDefined) {
            $this->previousCacheDir = AgaviConfig::get('core.cache_dir');
        }
        AgaviConfig::set('core.cache_dir', $this->cacheTestDir);

        if(!is_dir($this->cacheTestDir)) {
            @mkdir($this->cacheTestDir, 0775, true);
        }
        $this->clearDirectory($this->cacheTestDir);

        CacheManager::reset();
    }

    protected function restoreCache(): void
    {
        CacheManager::reset();

        if($this->cacheTestDir && is_dir($this->cacheTestDir)) {
            $this->clearDirectory($this->cacheTestDir);
            @rmdir($this->cacheTestDir);
        }

        if($this->cacheEnabledWasDefined) {
            AgaviConfig::set('core.cache_enabled', $this->previousCacheEnabled);
        } else {
            AgaviConfig::remove('core.cache_enabled');
        }

        if($this->useCacheWasDefined) {
            AgaviConfig::set('core.use_cache', $this->previousUseCache);
        } else {
            AgaviConfig::remove('core.use_cache');
        }

        if($this->cacheDirWasDefined) {
            AgaviConfig::set('core.cache_dir', $this->previousCacheDir);
        } else {
            AgaviConfig::remove('core.cache_dir');
        }

        $this->cacheTestDir = '';
    }

    private function clearDirectory(string $directory): void
    {
        if($directory === '' || !is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach($iterator as $file) {
            if($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
    }
}
