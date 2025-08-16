<?php
namespace Agavi\Routing;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Agavi Route Cache Manager - Handles persistent route caching
 * 
 * This class provides route result caching to avoid expensive route matching
 * operations for previously matched URLs. Particularly effective in FrankenPHP
 * where static variables persist between requests.
 */
class AgaviRouteCacheManager implements ResetInterface
{
    /**
     * @var self|null Singleton instance
     */
    private static $instance = null;
    
    /**
     * @var array Route cache storage
     */
    private $cache = [];
    
    /**
     * @var int Maximum cache size before eviction
     */
    private $maxSize = 5000;
    
    /**
     * @var int Cache hit counter
     */
    private $hits = 0;
    
    /**
     * @var int Cache miss counter
     */
    private $misses = 0;
    
    /**
     * Private constructor for singleton
     */
    private function __construct() {}
    
    /**
     * Get singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Get cached route result
     * 
     * @param string $key Cache key
     * @return array|null Cached route data or null if not found
     */
    public static function get($key, bool $countMiss = true)
    {
        $instance = self::getInstance();
        if (isset($instance->cache[$key])) {
            $instance->hits++;
            return $instance->cache[$key];
        }
        if($countMiss) { $instance->misses++; }
        return null;
    }

    /** Increment miss counter explicitly (used by optimized routing when using peek). */
    public static function recordMiss(): void
    {
        $instance = self::getInstance();
        $instance->misses++;
    }
    
    /**
     * Store route result in cache
     * 
     * @param string $key Cache key
     * @param array $value Route data to cache
     */
    public static function set($key, $value)
    {
        $instance = self::getInstance();
        if (count($instance->cache) >= $instance->maxSize) {
            // Simple FIFO eviction
            array_shift($instance->cache);
        }
        
        $instance->cache[$key] = $value;
    }
    
    /**
     * Get cache statistics
     * 
     * @return array Cache performance stats
     */
    public static function getStats(): array
    {
        $instance = self::getInstance();
        return [
            'size' => count($instance->cache),
            'hits' => $instance->hits,
            'misses' => $instance->misses,
            'hit_ratio' => $instance->hits / max(1, $instance->hits + $instance->misses),
            'max_size' => $instance->maxSize
        ];
    }
    
    /**
     * Clear cache and reset statistics
     */
    public static function clear()
    {
        $instance = self::getInstance();
        $instance->cache = [];
        $instance->hits = 0;
        $instance->misses = 0;
    }
    
    /**
     * Set maximum cache size
     * 
     * @param int $size Maximum number of cached entries
     */
    public static function setMaxSize($size)
    {
        $instance = self::getInstance();
        $instance->maxSize = $size;
    }
    
    /**
     * Get current cache size
     * 
     * @return int Number of cached entries
     */
    public static function getSize()
    {
        $instance = self::getInstance();
        return count($instance->cache);
    }

    /**
     * Reset cache state for FrankenPHP worker mode.
     * Called automatically by FrankenPHP between requests.
     * In worker mode, we typically want to preserve the cache for performance,
     * but reset statistics.
     */
    public function reset(): void
    {
        // By default, preserve cache but reset statistics for worker mode
        $this->hits = 0;
        $this->misses = 0;
        // Note: Cache is preserved for performance in worker mode
        // Use clear() method if you need to clear the cache entirely
    }

    /**
     * Static method to reset worker state - called by AgaviWorkerManager
     * 
     * @param bool $preserveCache Whether to preserve cached routes (default: true)
     * @param bool $resetStats Whether to reset hit/miss statistics (default: true)
     */
    public static function resetWorkerState(bool $preserveCache = true, bool $resetStats = true): void
    {
        if (self::$instance !== null) {
            if ($resetStats) {
                self::$instance->hits = 0;
                self::$instance->misses = 0;
            }
            
            if (!$preserveCache) {
                self::$instance->cache = [];
            }
        }
    }

}
