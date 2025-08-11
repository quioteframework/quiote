<?php

namespace Agavi\Util;

use Agavi\AgaviContext;
use Agavi\Config\AgaviConfig;
use Agavi\Routing\AgaviRouteCacheManager;
use Agavi\Routing\AgaviRouteTrie;
use Agavi\Routing\AgaviRoutingCallbackPool;
use Agavi\Routing\AgaviRoutingPerformanceMonitor;

/**
 * AgaviWorkerManager - Utilities for FrankenPHP worker mode compatibility
 * 
 * This class provides centralized state management for FrankenPHP worker mode,
 * ensuring that request-specific state is properly reset between requests while
 * preserving performance-critical cached data.
 * 
 * @package    agavi
 * @subpackage util
 * @author     Auto-generated for FrankenPHP compatibility
 * @since      1.1.0
 */
class AgaviWorkerManager
{
    /**
     * @var int Request counter
     */
    private static $requestCount = 0;
    
    /**
     * @var array Configuration for worker reset behavior
     */
    private static $config = [
        'preserve_route_cache' => true,
        'preserve_route_trie' => true,
        'preserve_callback_pool' => true,
        'reset_stats' => true,
        'reset_config' => false, // Config is static in worker mode - no need to reset
        'max_requests_before_cleanup' => 1000,
        'preserve_config_keys' => [
            'core.environment',
            'core.app_dir',
            'core.agavi_dir',
            'core.cache_dir',
            'core.config_dir',
            'core.default_context',
            // System action configurations
            'actions.default_module',
            'actions.default_action',
            'actions.error_404_module',
            'actions.error_404_action',
            'actions.unavailable_module',
            'actions.unavailable_action',
            'actions.module_disabled_module',
            'actions.module_disabled_action',
            'actions.secure_module',
            'actions.secure_action',
            // Other essential configurations
            'core.available',
            'core.use_database',
            'core.use_logging',
            'core.use_security',
            'core.use_translation',
            'core.use_routing',
            'core.namespace_prefix',
            // Module configurations (essential for validation and other module directives)
            'modules',
            'exception.default_template'
        ]
    ];
    
    /**
     * @var array Worker statistics
     */
    private static $statistics = [
        'reset_count' => 0,
        'initialization_time' => 0,
        'db_connections_active' => false,
        'apcu_acceleration' => false,
        'start_time' => 0,
        'last_reset_time' => 0
    ];

    /**
     * Internal helper to obtain a logger (if logging enabled and context available)
     */
    private static function getLogger()
    {
        try {
            $ctx = AgaviContext::getInstance(AgaviConfig::get('core.default_context', 'web'));
            return $ctx?->getLoggerManager()?->getLogger();
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    /**
     * Reset all framework state for the next request in worker mode
     * 
     * @param string|null $contextProfile Context profile to reset (null for all)
     * @param array $options Override default reset options
     */
    public static function resetForNextRequest($contextProfile = null, array $options = [])
    {
        // Backwards/defensive: allow calling resetForNextRequest($optionsArray) where first param was options
        if(is_array($contextProfile) && empty($options)) {
            $options = $contextProfile;
            $contextProfile = null; // treat as default context
        }
        $config = array_merge(self::$config, $options);
        self::$requestCount++;
        self::$statistics['reset_count']++;
        self::$statistics['last_reset_time'] = microtime(true);
        
    $logger = self::getLogger();
    // Reset context state using existing getInstance API and reset() method
    $logger?->debug('AgaviWorkerManager: Resetting context using getInstance approach');
        
        try {
            if ($contextProfile !== null) {
                // Reset specific context profile
                // Ensure contextProfile is string; if not, treat as default
                if(!is_string($contextProfile) || $contextProfile === '') {
                    $contextProfile = AgaviConfig::get('core.default_context', 'web');
                }
                $context = AgaviContext::getInstance($contextProfile);
                if ($context instanceof \Symfony\Contracts\Service\ResetInterface) {
                    $context->reset();
                    $logger?->debug("AgaviWorkerManager: Reset context profile: $contextProfile");
                } else {
                    $logger?->warning("AgaviWorkerManager: Context $contextProfile does not implement ResetInterface");
                }
            } else {
                // Reset all available contexts - we'll need to get the default context
                // Since we don't have access to all instances, reset the default context
                try {
                    $context = AgaviContext::getInstance();
                    if ($context instanceof \Symfony\Contracts\Service\ResetInterface) {
                        $context->reset();
                        $logger?->debug('AgaviWorkerManager: Reset default context');
                    } else {
                        $logger?->warning('AgaviWorkerManager: Default context does not implement ResetInterface');
                    }
                } catch (\Exception $e) {
                    $logger?->error('AgaviWorkerManager: Failed to get default context: ' . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            $logger?->error('AgaviWorkerManager: Context reset failed: ' . $e->getMessage());
        }
        
        // Reset configuration only if explicitly enabled (disabled by default in worker mode)
        if ($config['reset_config']) {
            AgaviConfig::resetWorkerState($config['preserve_config_keys']);
        }
        
        // Reset routing components with static reset methods
        if (class_exists('Agavi\Routing\AgaviRouteCacheManager')) {
            AgaviRouteCacheManager::resetWorkerState(
                $config['preserve_route_cache'],
                $config['reset_stats']
            );
        }
        
        if (class_exists('Agavi\Routing\AgaviRouteTrie')) {
            AgaviRouteTrie::resetWorkerState(
                $config['preserve_route_trie'],
                $config['reset_stats']
            );
        }
        
        if (class_exists('Agavi\Routing\AgaviRoutingCallbackPool')) {
            AgaviRoutingCallbackPool::resetWorkerState(
                $config['preserve_callback_pool'],
                $config['reset_stats']
            );
        }
        
        // Periodic deep cleanup
        if (self::$requestCount % $config['max_requests_before_cleanup'] === 0) {
            self::performDeepCleanup();
        }
        
        // Force garbage collection
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }
    
    /**
     * Perform deep cleanup periodically to prevent memory leaks
     */
    private static function performDeepCleanup()
    {
        // Clear all caches
        AgaviRouteCacheManager::resetWorkerState(false, true);
        AgaviRouteTrie::resetWorkerState(false, true);
        AgaviRoutingCallbackPool::resetWorkerState(false, true);
        
        // Force multiple garbage collection cycles
        if (function_exists('gc_collect_cycles')) {
            for ($i = 0; $i < 3; $i++) {
                gc_collect_cycles();
            }
        }
    }
    
    /**
     * Configure worker behavior
     * 
     * @param array $config Configuration options
     */
    public static function configure(array $config)
    {
        self::$config = array_merge(self::$config, $config);
    }
    
    /**
     * Get current request count
     * 
     * @return int Number of requests processed
     */
    public static function getRequestCount()
    {
        return self::$requestCount;
    }
    
    /**
     * Initialize the worker manager with configuration options
     * 
     * @param array $options Configuration options
     */
    public static function initialize(array $options = []): void
    {
        $startTime = microtime(true);
        
        // Merge with default configuration
        self::$config = array_merge(self::$config, $options);
        
        // Initialize statistics
        self::$statistics['start_time'] = $startTime;
        self::$statistics['db_connections_active'] = $options['preserve_database_connections'] ?? false;
        self::$statistics['apcu_acceleration'] = $options['apcu_acceleration'] ?? false;
        
        self::$statistics['initialization_time'] = microtime(true) - $startTime;
        
    self::getLogger()?->debug('AgaviWorkerManager initialized with options: ' . json_encode($options));
    }
    
    /**
     * Get worker statistics
     * 
     * @return array Worker statistics
     */
    public static function getStatistics(): array
    {
        $stats = self::$statistics;
        $stats['uptime'] = microtime(true) - self::$statistics['start_time'];
        $stats['memory_usage'] = memory_get_usage(true);
        $stats['memory_peak'] = memory_get_peak_usage(true);
        
        return $stats;
    }
    
    /**
     * Shutdown the worker manager and perform cleanup
     */
    public static function shutdown(): void
    {
    self::getLogger()?->info('AgaviWorkerManager shutting down...');
        
        // Perform final cleanup
        if (self::$statistics['db_connections_active']) {
            self::manageDatabaseConnections('close');
        }
        
        // Reset statistics
        self::$statistics['reset_count'] = 0;
        
    self::getLogger()?->info('AgaviWorkerManager shutdown complete');
    }
    
    /**
     * Create a FrankenPHP worker script template
     * 
     * @param string $bootstrapFile Path to your application's bootstrap file
     * @param array $options Worker configuration options
     * @return string Worker script content
     */
    public static function createWorkerScript($bootstrapFile, array $options = [])
    {
        $maxRequests = $options['max_requests'] ?? 0;
        $contextProfile = $options['context_profile'] ?? 'web';
        $afterRequestCallback = $options['after_request_callback'] ?? null;
        
    return '<?php
// FrankenPHP Worker Script for Agavi
// Generated by AgaviWorkerManager

// Prevent worker script termination when a client connection is interrupted
ignore_user_abort(true);

// Bootstrap your Agavi application
require_once \'' . $bootstrapFile . '\';

// Configure worker behavior
AgaviWorkerManager::configure(' . var_export($options, true) . ');

// Handler for processing requests
$handler = static function () use (&$context) {
    try {
        // Get/create context and dispatch request
        $context = AgaviContext::getInstance(\'' . $contextProfile . '\');
        $response = $context->getController()->dispatch();
        
        // Output is handled by response->send() in dispatch()
    return true;
    } catch (Exception $e) {
        // Log error and return error response
    // Using plain error_log in generated script is acceptable if framework not yet bootstrapped
    if (function_exists("error_log")) { error_log("Worker error: " . $e->getMessage()); }
        http_response_code(500);
        echo "Internal Server Error";
        return true;
    }
};

// Process requests in worker loop
$maxRequests = ' . $maxRequests . ';
for ($nbRequests = 0; !$maxRequests || $nbRequests < $maxRequests; ++$nbRequests) {
    $keepRunning = \\frankenphp_handle_request($handler);
    
    // Reset framework state for next request
    AgaviWorkerManager::resetForNextRequest(\'' . $contextProfile . '\');
    ' . ($afterRequestCallback ? 'if (is_callable(' . var_export($afterRequestCallback, true) . ')) { call_user_func(' . var_export($afterRequestCallback, true) . '); }' : '') . '
    
    if (!$keepRunning) break;
}

// Cleanup on shutdown
if (isset($context)) {
    $context->getController()->shutdown();
}
';
    }
    
    /**
     * 
     * This method should be called to reset any long-lived objects that might
     * hold request-specific state between FrankenPHP worker requests.
     * @param array $objects Array of objects to reset
     * @param bool $skipErrors Whether to continue if reset fails for some objects
     */
    public static function resetObjects(array $objects, bool $skipErrors = true)
    {
        foreach ($objects as $key => $object) {
            if (!is_object($object)) {
                continue;
            }
            
            if (!$object instanceof \Symfony\Contracts\Service\ResetInterface) {
                if (!$skipErrors) {
                    throw new \InvalidArgumentException(
                        sprintf('Object at key "%s" does not implement ResetInterface', $key)
                    );
                }
                continue;
            }
            
            try {
                $object->reset();
            } catch (\Exception $e) {
                if (!$skipErrors) {
                    throw $e;
                }
                // Log the error but continue
                self::getLogger()?->error(sprintf(
                    'Failed to reset object at key "%s" (%s): %s',
                    $key,
                    get_class($object),
                    $e->getMessage()
                ));
            }
        }
    }
    
    /**
     * Helper method for database connection management in worker mode
     * 
     * @param string $strategy Connection management strategy: 'keep' (default), 'close', or 'reset'
     */
    public static function manageDatabaseConnections(string $strategy = 'keep')
    {
        switch ($strategy) {
            case 'close':
                // Close all database connections
                if (class_exists('Propel\\Runtime\\Propel')) {
                    $propelClass = 'Propel\\Runtime\\Propel';
                    // dynamic to avoid hard dependency during static analysis
                    \call_user_func([$propelClass, 'close']);
                }
                // Add other ORMs as needed
                break;
                
            case 'reset':
                // Reset connections without closing (clean transactions)
                if (class_exists('Propel\\Runtime\\Propel')) {
                    $propelClass = 'Propel\\Runtime\\Propel';
                    try {
                        $con = \call_user_func([$propelClass, 'getConnection']);
                        if ($con && $con->inTransaction()) {
                            $con->rollback();
                            self::getLogger()?->warning('Warning: Uncommitted transaction rolled back in worker');
                        }
                    } catch (\Exception $e) {
                        self::getLogger()?->error('Propel transaction cleanup failed: ' . $e->getMessage());
                        // If reset fails, close and let it reconnect
                        \call_user_func([$propelClass, 'close']);
                    }
                }
                break;
                
            case 'keep':
            default:
                // Keep connections open (no action needed)
                // This is the recommended approach for performance
                break;
        }
    }
}
