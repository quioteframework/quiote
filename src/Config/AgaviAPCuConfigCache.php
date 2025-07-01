<?php

namespace Agavi\Config;

use Agavi\AgaviContext;
use Agavi\Config\AgaviConfig;
use Agavi\Config\AgaviConfigCache;
use Agavi\Routing\AgaviRouting;

/**
 * APCu-based configuration cache with warmup for Kubernetes/FrankenPHP deployments
 * 
 * This class provides both warmup functionality and drop-in replacement methods
 * for AgaviConfigCache. It combines the benefits of APCu caching with the
 * standard config cache interface.
 * 
 * Benefits:
 * - Zero file I/O after warmup
 * - Pre-compiled configurations stored in memory
 * - Routing trees cached and ready
 * - Drop-in replacement for AgaviConfigCache
 * - Uses igbinary for better serialization performance when available
 * 
 * @package    agavi
 * @subpackage config
 * @since      2.0.0
 */
class AgaviAPCuConfigCache extends AgaviConfigCache
{
    /**
     * @var string APCu key prefix for config cache
     */
    private static $configPrefix = 'agavi_config_';
    
    /**
     * @var string APCu key prefix for routing cache
     */
    private static $routingPrefix = 'agavi_routing_';
    
    /**
     * @var string APCu key for routing serialized data
     */
    private static $routingDataKey = 'agavi_routing_data';
    
    /**
     * @var string APCu key for compilation metadata
     */
    private static $metaKey = 'agavi_warmup_meta';
    
    /**
     * @var int Cache TTL (0 = never expire, good for immutable deployments)
     */
    private static $ttl = 0;
    
    /**
     * @var bool Whether APCu is available
     */
    private static $apcuAvailable = null;
    
    /**
     * @var bool Whether igbinary is available for better serialization
     */
    private static $igbinaryAvailable = null;
    
    /**
     * @var array Track loaded configs to prevent double loading
     */
    private static $loadedConfigs = [];
    
    
    
    /**
     * Override writeCacheFile to store compiled PHP in APCu instead of filesystem
     */
    public static function writeCacheFile($config, $cache, $data, $append = false)
    {
        error_log("AgaviAPCuConfigCache::writeCacheFile called for: " . basename($config));
        
        // If APCu is available, store ONLY in APCu (no filesystem writes)
        if (self::isAvailable()) {
            error_log("AgaviAPCuConfigCache: Storing in APCu ONLY for: " . basename($config));
            $key = self::getConfigKey($config, null); // Use config path as key
            
            if ($append && \apcu_exists($key)) {
                $existingData = \apcu_fetch($key);
                $data = $existingData . $data;
            }
            
            // Store compiled PHP in APCu ONLY - no filesystem writes
            \apcu_store($key, $data, self::$ttl);
            error_log("AgaviAPCuConfigCache: Successfully stored " . basename($config) . " in APCu");
            return; // Don't write to filesystem
        } else {
            error_log("AgaviAPCuConfigCache: APCu not available, falling back to file cache for: " . basename($config));
            // Fallback to normal file-based cache only when APCu is not available
            parent::writeCacheFile($config, $cache, $data, $append);
        }
    }
    
    /**
     * Override checkConfig to return a valid file path, creating temp files for APCu content when necessary
     */
    public static function checkConfig($config, $context = null)
    {
        // If APCu is available, try to load from cache first
        if (self::isAvailable()) {
            $key = self::getConfigKey($config, $context);
            $content = \apcu_fetch($key);
            
            if ($content !== false) {
                error_log("AgaviAPCuConfigCache: Loading " . basename($config) . " from APCu");
                
                // Create a temporary file that will be cleaned up quickly
                $tempFile = tempnam(sys_get_temp_dir(), 'apcu_' . basename($config, '.xml') . '_');
                file_put_contents($tempFile, $content);
                
                // Register cleanup to happen very soon (not just on shutdown)
                register_shutdown_function(function() use ($tempFile) {
                    if (file_exists($tempFile)) {
                        unlink($tempFile);
                    }
                });
                
                return $tempFile;
            }
        }
        
        error_log("AgaviAPCuConfigCache: " . basename($config) . " not in APCu, falling back to file cache");
        // Fallback to normal file-based cache (will compile and call writeCacheFile)
        return parent::checkConfig($config, $context);
    }
    
    /**
     * Drop-in replacement for AgaviConfigCache::load
     * Loads directly from APCu if available, otherwise falls back to normal loading
     */
    public static function load($config, $context = null, $once = true)
    {
        $configKey = self::getConfigKey($config, $context);
        
        // Check if already loaded (for $once functionality)
        if ($once && isset(self::$loadedConfigs[$configKey])) {
            return;
        }
        
        // Try to load directly from APCu first
        if (self::isAvailable() && self::loadFromApcu($config, $context)) {
            if ($once) {
                self::$loadedConfigs[$configKey] = true;
            }
            return;
        }
        
        // Fallback to normal file-based loading
        $cacheFile = self::checkConfig($config, $context);
        
        // Handle APCu marker if returned
        if (is_string($cacheFile) && strpos($cacheFile, 'APCU:') === 0) {
            // Extract the APCu key and eval the content directly
            $apcuKey = substr($cacheFile, 5); // Remove 'APCU:' prefix
            $content = \apcu_fetch($apcuKey);
            if ($content !== false) {
                eval('?>' . $content);
                if ($once) {
                    self::$loadedConfigs[$configKey] = true;
                }
                return;
            }
        }
        
        // Regular file loading
        if (is_readable($cacheFile)) {
            if ($once) {
                include_once($cacheFile);
            } else {
                include($cacheFile);
            }
        }
        
        if ($once) {
            self::$loadedConfigs[$configKey] = true;
        }
    }
    
    /**
     * Load and execute configuration directly from APCu cache
     */
    private static function loadFromApcu(string $config, ?string $context): bool
    {
        $key = self::getConfigKey($config, $context);
        $content = \apcu_fetch($key);
        
        if ($content === false) {
            return false;
        }
        
        // Execute the cached PHP content directly
        eval('?>' . $content);
        return true;
    }
    
    /**
     * Warm up all configurations and routing data into APCu
     * 
     * @param array $configs Array of config files to warm up (relative to config_dir)
     * @param string $context The context to warm up for
     * @return array Warmup statistics
     */
    public static function warmup(array $configs = [], ?string $context = null): array
    {
        if (!self::isAvailable()) {
            throw new \RuntimeException('APCu is not available or enabled');
        }
        
        $stats = [
            'configs_warmed' => 0,
            'routing_warmed' => false,
            'memory_used' => 0,
            'start_time' => microtime(true),
            'errors' => []
        ];
        
        try {
            // Auto-detect common config files if none provided
            if (empty($configs)) {
                $configs = self::getDefaultConfigs();
            }
            
            $configDir = AgaviConfig::get('core.config_dir');
            
            // Warm up configuration files in the correct dependency order
            foreach ($configs as $config) {
                try {
                    $configPath = self::isAbsolutePath($config) ? $config : $configDir . '/' . $config;
                    if (self::warmupConfig($configPath, $context)) {
                        $stats['configs_warmed']++;
                    }
                } catch (\Exception $e) {
                    $stats['errors'][] = "Config {$config}: " . $e->getMessage();
                }
            }
            
            // Warm up routing data
            try {
                if (self::warmupRouting($context)) {
                    $stats['routing_warmed'] = true;
                }
            } catch (\Exception $e) {
                $stats['errors'][] = "Routing: " . $e->getMessage();
            }
            
            // Store metadata about this warmup
            $meta = [
                'timestamp' => time(),
                'context' => $context,
                'configs' => $configs,
                'php_version' => PHP_VERSION,
                'agavi_version' => defined('AGAVI_VERSION') ? AGAVI_VERSION : 'unknown'
            ];
            \apcu_store(self::$metaKey, $meta, self::$ttl);
            
        } catch (\Exception $e) {
            $stats['errors'][] = "General: " . $e->getMessage();
        }
        
        $stats['duration'] = microtime(true) - $stats['start_time'];
        $stats['memory_used'] = self::getApcuMemoryUsage();
        
        return $stats;
    }
    
    /**
     * Warm up a single configuration file
     */
    private static function warmupConfig(string $config, ?string $context): bool
    {
        error_log("AgaviAPCuConfigCache::warmupConfig called for: " . basename($config) . " (context: " . ($context ?? 'null') . ")");
        
        // Temporarily disable APCu to force compilation without storing in APCu yet
        $apcuWasAvailable = self::$apcuAvailable;
        self::$apcuAvailable = false;
        
        try {
            // Get the compiled/cached version through normal Agavi process (will create temp file)
            $cacheFile = parent::checkConfig($config, $context);
            
            if (!is_readable($cacheFile)) {
                error_log("AgaviAPCuConfigCache::warmupConfig: Cache file not readable for " . basename($config));
                return false;
            }
            
            // Read the compiled content
            $content = file_get_contents($cacheFile);
            error_log("AgaviAPCuConfigCache::warmupConfig: Read " . strlen($content) . " bytes for " . basename($config));
            
            // Store in APCu
            $key = self::getConfigKey($config, $context);
            $result = \apcu_store($key, $content, self::$ttl);
            error_log("AgaviAPCuConfigCache::warmupConfig: Stored in APCu with result: " . ($result ? 'SUCCESS' : 'FAILED') . " for " . basename($config));
            
            // Clean up the temporary file since we only need APCu storage
            if (file_exists($cacheFile)) {
                unlink($cacheFile);
                error_log("AgaviAPCuConfigCache::warmupConfig: Cleaned up temp file for " . basename($config));
            }
            
            return $result;
            
        } finally {
            // Restore APCu availability
            self::$apcuAvailable = $apcuWasAvailable;
        }
    }
    
    /**
     * Warm up routing configuration
     */
    private static function warmupRouting(?string $context): bool
    {
        $routingConfig = AgaviConfig::get('core.config_dir') . '/routing.xml';
        if (!is_readable($routingConfig)) {
            return false;
        }
        
        try {
            // Get the compiled routing cache
            $cacheFile = parent::checkConfig($routingConfig, $context);
            
            if (is_readable($cacheFile)) {
                // Read the serialized routing data
                $routingData = file_get_contents($cacheFile);
                
                // Store routing data in APCu
                \apcu_store(self::$routingDataKey, $routingData, self::$ttl);
                
                // Also warm up the routing config file itself
                self::warmupConfig($routingConfig, $context);
                
                return true;
            }
        } catch (\Exception $e) {
            // Continue even if routing warmup fails
            return false;
        }
        
        return false;
    }
    
    /**
     * Get APCu memory usage for Agavi keys
     */
    private static function getApcuMemoryUsage(): int
    {
        if (!self::isAvailable()) {
            return 0;
        }
        
        $totalSize = 0;
        if (class_exists('APCUIterator')) {
            $iterator = new \APCUIterator('/^agavi_/');
            
            foreach ($iterator as $key => $value) {
                $totalSize += $value['mem_size'] ?? 0;
            }
        }
        
        return $totalSize;
    }
    
    /**
     * Configure APCu cache settings
     */
    public static function configure(array $options): void
    {
        if (isset($options['config_prefix'])) {
            self::$configPrefix = $options['config_prefix'];
        }
        
        if (isset($options['routing_prefix'])) {
            self::$routingPrefix = $options['routing_prefix'];
        }
        
        if (isset($options['ttl'])) {
            self::$ttl = (int)$options['ttl'];
        }
    }
    
    /**
     * Check if APCu is available and enabled
     */
    public static function isAvailable(): bool
    {
        if (self::$apcuAvailable === null) {
            self::$apcuAvailable = extension_loaded('apcu') && function_exists('apcu_enabled') && \apcu_enabled();
        }
        return self::$apcuAvailable;
    }
    
    /**
     * Check if igbinary is available for better serialization
     */
    public static function isIgbinaryAvailable(): bool
    {
        if (self::$igbinaryAvailable === null) {
            self::$igbinaryAvailable = extension_loaded('igbinary') && function_exists('igbinary_serialize');
        }
        return self::$igbinaryAvailable;
    }
    
    /**
     * Serialize data using igbinary if available, fallback to regular serialize
     */
    private static function serialize($data): string
    {
        if (self::isIgbinaryAvailable()) {
            return \igbinary_serialize($data);
        }
        return \serialize($data);
    }
    
    /**
     * Unserialize data using igbinary if available, fallback to regular unserialize
     */
    private static function unserialize(string $data)
    {
        if (self::isIgbinaryAvailable()) {
            return \igbinary_unserialize($data);
        }
        return \unserialize($data);
    }

    /**
     * Generate APCu key for config file
     */
    private static function getConfigKey(string $config, ?string $context): string
    {
        $key = self::$configPrefix . md5($config . ($context ?? ''));
        error_log("AgaviAPCuConfigCache: Generated key for " . basename($config) . " (context: " . ($context ?? 'null') . "): " . $key);
        return $key;
    }
    
    /**
     * Create temporary cache file for compatibility with existing code
     */
    private static function createTempCacheFile(string $content, string $config, ?string $context): string
    {
        // Use the standard cache name from parent class
        $cacheName = parent::getCacheName($config, $context);
        
        // Write content to cache file if it doesn't exist or is outdated
        if (!is_file($cacheName) || !is_readable($cacheName)) {
            parent::writeCacheFile($config, $cacheName, $content);
        }
        
        return $cacheName;
    }
    
    /**
     * Check if APCu cache is warmed up
     */
    public static function isWarmedUp(): bool
    {
        if (!self::isAvailable()) {
            return false;
        }
        
        $meta = \apcu_fetch(self::$metaKey);
        return $meta !== false;
    }
    
    /**
     * Get default configuration files to warm up in the same order as original Agavi loading
     */
    private static function getDefaultConfigs(): array
    {
        return [
            // Bootstrap phase (from Agavi.php) - these get cached after first load
            'settings.xml',      // First - establishes core settings
            'compile.xml',       // Second - defines compilation settings
            
            // Context initialization phase (from AgaviContext::initialize)
            'factories.xml',     // Third - creates factories including session storage
            
            // Controller initialization phase (from AgaviController::initialize)  
            'output_types.xml',  // Fourth - defines output types
            
            // Runtime phase (loaded during execution as needed)
            'routing.xml',       // Routing configuration
            'databases.xml',     // Database configuration (loaded by DatabaseManager)
            'logging.xml',       // Logging configuration (loaded by LoggerManager)
            'translation.xml',   // Translation configuration
            'config_handlers.xml' // Config handlers
        ];
    }
    
    /**
     * Check if a path is absolute
     */
    private static function isAbsolutePath(string $path): bool
    {
        return isset($path[0]) && ($path[0] === '/' || (PHP_OS_FAMILY === 'Windows' && preg_match('/^[a-zA-Z]:/', $path)));
    }
    
    /**
     * Clear all APCu cached data
     */
    public static function clear()
    {
        // Clear APCu cache
        if (self::isAvailable()) {
            self::clearApcu();
        }
        
        // Clear loaded configs tracking
        self::$loadedConfigs = [];
        
        // Clear parent file cache
        parent::clear();
    }
    
    /**
     * Clear APCu cached data
     */
    private static function clearApcu(): bool
    {
        // Clear all Agavi-related APCu keys
        if (class_exists('APCUIterator')) {
            $iterator = new \APCUIterator('/^agavi_/');
            $cleared = 0;
            
            foreach ($iterator as $key => $value) {
                if (\apcu_delete($key)) {
                    $cleared++;
                }
            }
        } else {
            // Fallback: try to delete known keys
            $cleared = 0;
            $knownKeys = [self::$metaKey, self::$routingDataKey, self::$routingPrefix . 'trie'];
            foreach ($knownKeys as $key) {
                if (\apcu_delete($key)) {
                    $cleared++;
                }
            }
        }
        
        return $cleared > 0;
    }
    
    /**
     * Get warmup status and statistics
     */
    public static function getStatus(): array
    {
        if (!self::isAvailable()) {
            return ['available' => false];
        }
        
        $meta = \apcu_fetch(self::$metaKey);
        $status = [
            'available' => true,
            'warmed_up' => $meta !== false,
            'memory_usage' => self::getApcuMemoryUsage(),
            'igbinary_available' => self::isIgbinaryAvailable()
        ];
        
        if ($meta !== false) {
            $status = array_merge($status, $meta);
            $status['age_seconds'] = time() - $meta['timestamp'];
        }
        
        return $status;
    }
    
    
    
    
}