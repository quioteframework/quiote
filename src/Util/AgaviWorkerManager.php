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
        'max_requests_before_cleanup' => 1000,
        'preserve_config_keys' => [
            'core.environment',
            'core.app_dir',
            'core.agavi_dir',
            'core.cache_dir',
            'core.config_dir',
            'core.default_context'
        ]
    ];
    
    /**
     * Reset all framework state for the next request in worker mode
     * 
     * @param string|null $contextProfile Context profile to reset (null for all)
     * @param array $options Override default reset options
     */
    public static function resetForNextRequest($contextProfile = null, array $options = [])
    {
        $config = array_merge(self::$config, $options);
        self::$requestCount++;
        
        // Reset context state using Symfony ResetInterface
        AgaviContext::resetWorkerState($contextProfile);
        
        // Reset configuration (preserving core settings)
        AgaviConfig::resetWorkerState($config['preserve_config_keys']);
        
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
     * Get worker statistics
     * 
     * @return array Statistics about worker state
     */
    public static function getStats()
    {
        $stats = [
            'request_count' => self::$requestCount,
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
        ];
        
        // Add routing stats if available
        if (class_exists('Agavi\Routing\AgaviRouteCacheManager')) {
            $stats['route_cache'] = AgaviRouteCacheManager::getStats();
        }
        
        if (class_exists('Agavi\Routing\AgaviRouteTrie')) {
            $stats['route_trie'] = AgaviRouteTrie::getStats();
        }
        
        if (class_exists('Agavi\Routing\AgaviRoutingCallbackPool')) {
            $stats['callback_pool'] = AgaviRoutingCallbackPool::getStats();
        }
        
        return $stats;
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
        error_log("Worker error: " . $e->getMessage());
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
     * Reset instance objects that implement ResetInterface
     * 
     * This method should be called to reset any long-lived objects that might
     * hold request-specific state between FrankenPHP worker requests.
     * 
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
                error_log(sprintf(
                    'Failed to reset object at key "%s" (%s): %s',
                    $key,
                    get_class($object),
                    $e->getMessage()
                ));
            }
        }
    }
}
