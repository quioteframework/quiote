<?php
namespace Quiote\Routing;

use Quiote\Exception\QuioteException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Quiote Routing Callback Pool - Reuses callback instances for performance
 * This class maintains a pool of callback instances to avoid the overhead
 * of creating new instances for each route match. Particularly beneficial
 * for complex routing configurations with many callbacks.
 */
class RoutingCallbackPool implements ResetInterface
{
    /**
     * @var self|null Singleton instance for ResetInterface
     */
    private static $resetInstance = null;
    
    /**
     * @var array Pool of callback instances
     */
    private static $instances = [];
    
    /**
     * @var int Maximum number of pooled instances
     */
    private static $maxInstances = 100;
    
    /**
     * @var int Pool access counter
     */
    private static $accessCount = 0;
    
    /**
     * Get reset instance for ResetInterface compliance
     */
    public static function getResetInstance(): self
    {
        if (self::$resetInstance === null) {
            self::$resetInstance = new self();
        }
        return self::$resetInstance;
    }
    
    /**
     * Get or create callback instance from pool
     * @param string $className Callback class name
     * @param array $parameters Callback parameters
     * @return object Callback instance
     */
    public static function getInstance($className, $parameters = [])
    {
        $key = $className . '_' . md5(serialize($parameters));
        
        if (!isset(self::$instances[$key])) {
            if (count(self::$instances) >= self::$maxInstances) {
                // Remove oldest instance (FIFO)
                array_shift(self::$instances);
            }
            
            if (class_exists($className)) {
                $instance = new $className();
                
                // Set parameters if the instance supports it
                if (method_exists($instance, 'setParameters')) {
                    $instance->setParameters($parameters);
                } else if (method_exists($instance, 'initialize')) {
                    $instance->initialize($parameters);
                }
                
                self::$instances[$key] = $instance;
            } else {
                throw new QuioteException("Callback class '{$className}' does not exist");
            }
        }
        
        self::$accessCount++;
        return self::$instances[$key];
    }
    
    /**
     * Get current pool size
     * @return int Number of pooled instances
     */
    public static function getPoolSize()
    {
        return count(self::$instances);
    }
    
    /**
     * Clear the callback pool
     */
    public static function clearPool()
    {
        self::$instances = [];
        self::$accessCount = 0;
    }
    
    /**
     * Set maximum pool size
     * @param int $size Maximum number of pooled instances
     */
    public static function setMaxInstances($size)
    {
        self::$maxInstances = $size;
    }
    
    /**
     * Get pool statistics
     * @return array Pool performance stats
     */
    public static function getStats()
    {
        return [
            'pool_size' => count(self::$instances),
            'max_instances' => self::$maxInstances,
            'access_count' => self::$accessCount,
            'memory_usage' => memory_get_usage()
        ];
    }
    
    /**
     * Remove specific instance from pool
     * @param string $className Callback class name
     * @param array $parameters Callback parameters
     */
    public static function removeInstance($className, $parameters = [])
    {
        $key = $className . '_' . md5(serialize($parameters));
        unset(self::$instances[$key]);
    }

    /**
     * Reset callback pool state for FrankenPHP worker mode.
     * Called automatically by FrankenPHP between requests.
     * In worker mode, we typically want to preserve pooled instances for performance,
     * but reset statistics.
     */
    public function reset(): void
    {
        // By default, preserve instances but reset statistics for worker mode
        self::$accessCount = 0;
        // Note: Instances are preserved for performance in worker mode
        // Call removeInstance() or use manual cleanup if needed
    }

    /**
     * Static method to reset worker state - called by WorkerManager
     * @param bool $preservePool Whether to preserve pooled instances (default: true)
     * @param bool $resetStats Whether to reset access statistics (default: true)
     */
    public static function resetWorkerState(bool $preservePool = true, bool $resetStats = true): void
    {
        if ($resetStats) {
            self::$accessCount = 0;
        }
        
        if (!$preservePool) {
            self::$instances = [];
        }
    }
}
