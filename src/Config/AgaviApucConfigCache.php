<?php

namespace Agavi\Config;

use Agavi\AgaviContext;
use Agavi\Config\AgaviConfig;
use Agavi\Config\AgaviConfigCache;
use Agavi\Routing\AgaviRouting;

/**
 * APCu-based configuration and routing warmup for Kubernetes/FrankenPHP deployments
 * 
 * In containerized environments (Kubernetes), configuration and routes are immutable
 * within a pod's lifecycle. This class provides APCu-based caching to eliminate
 * file I/O and compilation overhead on every request.
 * 
 * Benefits:
 * - Zero file I/O after warmup
 * - Pre-compiled configurations stored in memory
 * - Routing trees cached and ready
 * - Dramatic performance improvement for cold starts
 * - Uses igbinary for better serialization performance when available
 * 
 * @package    agavi
 * @subpackage config
 * @author     Auto-generated for Kubernetes/FrankenPHP optimization
 * @since      1.1.0
 */
class AgaviApucConfigCache
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
     * Warm up all configurations and routing data into APCu
     * 
     * @param array $configs Array of config files to warm up
     * @param string $context The context to warm up for
     * @return array Warmup statistics
     */
    public static function warmup(array $configs = [], string $context = null): array
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
            
            // Warm up configuration files
            foreach ($configs as $config) {
                try {
                    if (self::warmupConfig($config, $context)) {
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
        // Get the compiled/cached version through normal Agavi process
        $cacheFile = AgaviConfigCache::checkConfig($config, $context);
        
        if (!is_readable($cacheFile)) {
            return false;
        }
        
        // Read the compiled content
        $content = file_get_contents($cacheFile);
        
        // Store in APCu
        $key = self::$configPrefix . md5($config . ($context ?? ''));
        return \apcu_store($key, $content, self::$ttl);
    }
    
    /**
     * Warm up routing configuration
     */
    private static function warmupRouting(?string $context): bool
    {
        // Create a temporary routing instance to compile routes
        $routing = new AgaviRouting();
        
        // Load routing configuration through normal process
        $routingConfig = AgaviConfig::get('core.config_dir') . '/routing.xml';
        if (is_readable($routingConfig)) {
            $cacheFile = AgaviConfigCache::checkConfig($routingConfig, $context);
            
            if (is_readable($cacheFile)) {
                // Get the serialized routing data
                $routingData = file_get_contents($cacheFile);
                
                // Store routing data in APCu
                \apcu_store(self::$routingDataKey, $routingData, self::$ttl);
                
                // Also compile the route trie for faster matching
                try {
                    $routes = self::unserialize($routingData);
                    $routing->importRoutes($routes);
                    
                    // Pre-build route trie if available
                    if (class_exists('Agavi\\Routing\\AgaviRouteTrie')) {
                        \Agavi\Routing\AgaviRouteTrie::build($routes);
                        $trieData = \Agavi\Routing\AgaviRouteTrie::exportTrie();
                        if ($trieData) {
                            \apcu_store(self::$routingPrefix . 'trie', self::serialize($trieData), self::$ttl);
                        }
                    }
                    
                    return true;
                } catch (\Exception $e) {
                    // If trie building fails, still return success for basic routing
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Load configuration from APCu cache
     */
    public static function loadConfig(string $config, ?string $context = null, bool $once = true): bool
    {
        if (!self::isAvailable()) {
            return false;
        }
        
        $key = self::$configPrefix . md5($config . ($context ?? ''));
        $content = \apcu_fetch($key);
        
        if ($content === false) {
            return false;
        }
        
        // Execute the cached PHP code
        if ($once && self::isConfigLoaded($key)) {
            return true;
        }
        
        // Mark as loaded for $once functionality
        if ($once) {
            self::markConfigLoaded($key);
        }
        
        // Execute the PHP content
        eval('?>' . $content);
        
        return true;
    }
    
    /**
     * Load routing data from APCu cache
     */
    public static function loadRouting(AgaviRouting $routing, ?string $context = null): bool
    {
        if (!self::isAvailable()) {
            return false;
        }
        
        // Load basic routing data
        $routingData = \apcu_fetch(self::$routingDataKey);
        if ($routingData === false) {
            return false;
        }
        
        $routes = self::unserialize($routingData);
        $routing->importRoutes($routes);
        
        // Load pre-built trie if available
        $trieData = \apcu_fetch(self::$routingPrefix . 'trie');
        if ($trieData !== false && class_exists('Agavi\\Routing\\AgaviRouteTrie')) {
            $unserializedTrie = self::unserialize($trieData);
            \Agavi\Routing\AgaviRouteTrie::importTrie($unserializedTrie);
        }
        
        return true;
    }
    
    /**
     * Check if configuration is already loaded (for $once functionality)
     */
    private static function isConfigLoaded(string $key): bool
    {
        static $loaded = [];
        return isset($loaded[$key]);
    }
    
    /**
     * Mark configuration as loaded
     */
    private static function markConfigLoaded(string $key): void
    {
        static $loaded = [];
        $loaded[$key] = true;
    }
    
    /**
     * Clear all APCu cached data
     */
    public static function clear(): bool
    {
        if (!self::isAvailable()) {
            return false;
        }
        
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
            'memory_usage' => self::getApcuMemoryUsage()
        ];
        
        if ($meta !== false) {
            $status = array_merge($status, $meta);
            $status['age_seconds'] = time() - $meta['timestamp'];
        }
        
        return $status;
    }
    
    /**
     * Get default configuration files to warm up
     */
    private static function getDefaultConfigs(): array
    {
        $configDir = AgaviConfig::get('core.config_dir');
        
        return [
            'autoload.xml',
            'compile.xml',
            'config_handlers.xml',
            'databases.xml',
            'factories.xml',
            'filters.xml',
            'logging.xml',
            'output_types.xml',
            'routing.xml',
            'settings.xml',
            'translation.xml',
            'validators.xml'
        ];
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
}
