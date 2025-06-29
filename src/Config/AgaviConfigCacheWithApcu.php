<?php

namespace Agavi\Config;

/**
 * Enhanced AgaviConfigCache with APCu integration
 * 
 * This class extends the standard config cache with APCu-based warmup
 * for Kubernetes/containerized deployments where configs are immutable.
 * 
 * @package    agavi
 * @subpackage config
 * @since      2.0.0
 */
class AgaviConfigCacheWithApcu extends AgaviConfigCache
{
    /**
     * @var bool Whether to use APCu caching
     */
    private static $useApcu = null;
    
    /**
     * Load configuration with APCu fallback
     * 
     * @param string $config Configuration file path
     * @param string|null $context Context name
     * @param bool $once Whether to load only once per request
     */
    public static function load($config, $context = null, $once = true)
    {
        // Try APCu first if enabled
        if (self::shouldUseApcu() && AgaviApucConfigCache::loadConfig($config, $context, $once)) {
            return;
        }
        
        // Fallback to normal file-based loading
        parent::load($config, $context, $once);
    }
    
    /**
     * Check configuration with APCu awareness
     */
    public static function checkConfig($config, $context = null)
    {
        // If APCu is warmed up, we don't need to check file timestamps
        if (self::shouldUseApcu()) {
            $status = AgaviApucConfigCache::getStatus();
            if ($status['warmed_up']) {
                // Return a dummy cache name - we won't actually use it
                return self::getCacheName($config, $context);
            }
        }
        
        // Fallback to normal file checking
        return parent::checkConfig($config, $context);
    }
    
    /**
     * Clear cache including APCu
     */
    public static function clear()
    {
        // Clear APCu cache
        if (self::shouldUseApcu()) {
            AgaviApucConfigCache::clear();
        }
        
        // Clear file cache
        parent::clear();
    }
    
    /**
     * Determine if APCu should be used
     */
    private static function shouldUseApcu(): bool
    {
        if (self::$useApcu === null) {
            // Enable APCu in production environments or when explicitly enabled
            self::$useApcu = (
                AgaviApucConfigCache::isAvailable() &&
                (
                    \Agavi\Config\AgaviConfig::get('core.use_apcu_cache', false) ||
                    (php_sapi_name() === 'frankenphp' && !AgaviConfig::get('core.debug', false))
                )
            );
        }
        
        return self::$useApcu;
    }
    
    /**
     * Force enable/disable APCu usage
     */
    public static function setUseApcu(bool $use): void
    {
        self::$useApcu = $use;
    }
}
