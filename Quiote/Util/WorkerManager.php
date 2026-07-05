<?php

namespace Quiote\Util;

use Quiote\Context;
use Quiote\Config\Config;
use Quiote\Routing\RoutingCallbackPool;

/**
 * WorkerManager - Utilities for FrankenPHP worker mode compatibility
 * This class provides centralized state management for FrankenPHP worker mode,
 * ensuring that request-specific state is properly reset between requests while
 * preserving performance-critical cached data.
 * @since      1.0.0
 */
class WorkerManager
{
    /**
     * @var int Request counter
     */
    private static $requestCount = 0;
    
    /**
     * @var array<string, mixed> Configuration for worker reset behavior
     */
    private static $config = [
    // Removed: route cache + trie preservation (legacy stack removed)
        'preserve_callback_pool' => true,
        'reset_stats' => true,
        'reset_config' => false, // Config is static in worker mode - no need to reset
        'max_requests_before_cleanup' => 1000,
        'preserve_config_keys' => [
            'core.environment',
            'core.app_dir',
            'core.quiote_dir',
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
        ]
    ];
    
    /**
     * @var array<string, mixed> Worker statistics
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
    private static function getLogger(): \Quiote\Logging\CategoryLogger
    {
        return \Quiote\Logging\Log::create('Quiote.Util.WorkerManager');
    }
    
    /**
     * Reset all framework state for the next request in worker mode
     * @param string|array<string, mixed>|null $contextProfile Context profile to reset (null for all).
     *        For backwards compatibility, an options array may be passed here instead.
     * @param array<string, mixed> $options Override default reset options
     * @return void
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
    $logger->debug('WorkerManager: Resetting context using getInstance approach');
        
        try {
            if ($contextProfile !== null) {
                // Reset specific context profile
                // Ensure contextProfile is string; if not, treat as default
                if(!is_string($contextProfile) || $contextProfile === '') {
                    $contextProfile = Config::get('core.default_context', 'web');
                }
                $context = Context::getInstance($contextProfile);
                $context->reset();
                $logger->debug("[WorkerManager] Reset context profile: $contextProfile");
            } else {
                // Reset all available contexts - we'll need to get the default context
                // Since we don't have access to all instances, reset the default context
                try {
                    $context = Context::getInstance();
                    $context->reset();
                    $logger->debug('[WorkerManager] Reset default context');
                } catch (\Exception $e) {
                    $logger->error('[WorkerManager] Failed to get default context: ' . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            $logger->error('[WorkerManager] Context reset failed: ' . $e->getMessage());
        }
        
        // Reset configuration only if explicitly enabled (disabled by default in worker mode)
        if ($config['reset_config']) {
            Config::resetWorkerState($config['preserve_config_keys']);
        }
        
        // Reset routing components with static reset methods
        if (class_exists(\Quiote\Routing\RoutingCallbackPool::class)) {
            RoutingCallbackPool::resetWorkerState(
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
     * @return void
     */
    private static function performDeepCleanup()
    {
        // Clear all caches
        if (class_exists(\Quiote\Routing\RoutingCallbackPool::class)) {
            RoutingCallbackPool::resetWorkerState(false, true);
        }
        
        // Force multiple garbage collection cycles
        if (function_exists('gc_collect_cycles')) {
            for ($i = 0; $i < 3; $i++) {
                gc_collect_cycles();
            }
        }
    }
    
    /**
     * Configure worker behavior
     * @param array<string, mixed> $config Configuration options
     * @return void
     */
    public static function configure(array $config)
    {
        self::$config = array_merge(self::$config, $config);
    }
    
    /**
     * Get current request count
     * @return int Number of requests processed
     */
    public static function getRequestCount()
    {
        return self::$requestCount;
    }
    
    /**
     * Initialize the worker manager with configuration options
     * @param array<string, mixed> $options Configuration options
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
        
    self::getLogger()->debug('WorkerManager initialized with options: ' . json_encode($options));
    }
    
    /**
     * Get worker statistics
     * @return array<string, mixed> Worker statistics
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
    self::getLogger()->info('WorkerManager shutting down...');
        
        // Perform final cleanup
        if (self::$statistics['db_connections_active']) {
            self::manageDatabaseConnections('close');
        }
        
        // Reset statistics
        self::$statistics['reset_count'] = 0;
        
    self::getLogger()->info('WorkerManager shutdown complete');
    }
    
    /**
     * Create a FrankenPHP worker script template
     * @param string $bootstrapFile Path to your application's bootstrap file
     * @param array<string, mixed> $options Worker configuration options
     * @return string Worker script content
     */
    public static function createWorkerScript($bootstrapFile, array $options = [])
    {
        $maxRequests = $options['max_requests'] ?? 0;
        $contextProfile = $options['context_profile'] ?? 'web';
        $afterRequestCallback = $options['after_request_callback'] ?? null;
        
    return '<?php
// FrankenPHP Worker Script for Quiote
// Generated by WorkerManager

// Prevent worker script termination when a client connection is interrupted
ignore_user_abort(true);

// Bootstrap your Quiote application
require_once \'' . $bootstrapFile . '\';

// Configure worker behavior
WorkerManager::configure(' . var_export($options, true) . ');

// Handler for processing requests
$handler = static function () use (&$context) {
    try {
        // Get/create context and dispatch request
        $context = Context::getInstance(\'' . $contextProfile . '\');
        $response = $context->getController()->dispatch();
        
        // Output is handled by response->send() in dispatch()
    return true;
    } catch (\Exception $e) {
        // Log error and return error response
    \\Quiote\\Logging\\Log::create(\'Quiote.Worker\')->error(\'Worker error: \' . $e->getMessage());
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
    WorkerManager::resetForNextRequest(\'' . $contextProfile . '\');
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
     * This method should be called to reset any long-lived objects that might
     * hold request-specific state between FrankenPHP worker requests.
     * @param array<int|string, mixed> $objects Array of objects to reset
     * @param bool $skipErrors Whether to continue if reset fails for some objects
     * @return void
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
                self::getLogger()->error(sprintf(
                    'Failed to reset object at key "%s" (%s): %s',
                    $key,
                    $object::class,
                    $e->getMessage()
                ));
            }
        }
    }
    
    /**
     * Helper method for database connection management in worker mode
     * @param string $strategy Connection management strategy: 'keep' (default), 'close', or 'reset'
     * @return void
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
                            self::getLogger()->warning('Warning: Uncommitted transaction rolled back in worker');
                        }
                    } catch (\Exception $e) {
                        self::getLogger()->error('Propel transaction cleanup failed: ' . $e->getMessage());
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
