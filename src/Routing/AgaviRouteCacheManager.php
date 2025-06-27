<?php
namespace Agavi\Routing;

/**
 * Agavi Route Cache Manager - Handles persistent route caching
 * 
 * This class provides route result caching to avoid expensive route matching
 * operations for previously matched URLs. Particularly effective in FrankenPHP
 * where static variables persist between requests.
 */
class AgaviRouteCacheManager
{
    /**
     * @var array Route cache storage
     */
    private static $cache = [];
    
    /**
     * @var int Maximum cache size before eviction
     */
    private static $maxSize = 5000;
    
    /**
     * @var int Cache hit counter
     */
    private static $hits = 0;
    
    /**
     * @var int Cache miss counter
     */
    private static $misses = 0;
    
    /**
     * Get cached route result
     * 
     * @param string $key Cache key
     * @return array|null Cached route data or null if not found
     */
    public static function get($key)
    {
        if (isset(self::$cache[$key])) {
            self::$hits++;
            return self::$cache[$key];
        }
        
        self::$misses++;
        return null;
    }
    
    /**
     * Store route result in cache
     * 
     * @param string $key Cache key
     * @param array $value Route data to cache
     */
    public static function set($key, $value)
    {
        if (count(self::$cache) >= self::$maxSize) {
            // Simple FIFO eviction
            array_shift(self::$cache);
        }
        
        self::$cache[$key] = $value;
    }
    
    /**
     * Get cache statistics
     * 
     * @return array Cache performance stats
     */
    public static function getStats()
    {
        return [
            'size' => count(self::$cache),
            'hits' => self::$hits,
            'misses' => self::$misses,
            'hit_ratio' => self::$hits / max(1, self::$hits + self::$misses)
        ];
    }
    
    /**
     * Clear cache and reset statistics
     */
    public static function clear()
    {
        self::$cache = [];
        self::$hits = 0;
        self::$misses = 0;
    }
    
    /**
     * Set maximum cache size
     * 
     * @param int $size Maximum number of cached entries
     */
    public static function setMaxSize($size)
    {
        self::$maxSize = $size;
    }
    
    /**
     * Get current cache size
     * 
     * @return int Number of cached entries
     */
    public static function getSize()
    {
        return count(self::$cache);
    }
}
