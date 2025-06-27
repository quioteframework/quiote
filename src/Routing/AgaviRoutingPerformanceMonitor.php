<?php
namespace Agavi\Routing;

use InvalidArgumentException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Agavi Routing Performance Monitor - Tracks routing performance metrics
 * 
 * This class collects and analyzes routing performance data to help
 * identify bottlenecks and measure the effectiveness of optimizations.
 */
class AgaviRoutingPerformanceMonitor implements ResetInterface
{
    /**
     * @var self|null Singleton instance for ResetInterface
     */
    private static $resetInstance = null;
    
    /**
     * @var array Performance statistics
     */
    private static $stats = [
        'total_requests' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'total_time' => 0,
        'min_time' => PHP_FLOAT_MAX,
        'max_time' => 0,
        'route_matches' => 0,
        'route_failures' => 0
    ];
    
    /**
     * @var array Request timing data
     */
    private static $timings = [];
    
    /**
     * @var int Maximum number of timing samples to keep
     */
    private static $maxTimingSamples = 1000;
    
    /**
     * @var bool Whether detailed timing is enabled
     */
    private static $detailedTiming = false;
    
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
     * Start timing a routing operation
     * 
     * @param string $operation Operation identifier
     * @return float Start timestamp
     */
    public static function startTiming($operation = 'routing')
    {
        $startTime = microtime(true);
        
        if (self::$detailedTiming) {
            self::$timings[$operation] = [
                'start' => $startTime,
                'operation' => $operation
            ];
        }
        
        return $startTime;
    }
    
    /**
     * End timing a routing operation
     * 
     * @param float $startTime Start timestamp
     * @param string $operation Operation identifier
     * @return float Duration in seconds
     */
    public static function endTiming($startTime, $operation = 'routing')
    {
        $duration = microtime(true) - $startTime;
        
        self::$stats['total_requests']++;
        self::$stats['total_time'] += $duration;
        self::$stats['min_time'] = min(self::$stats['min_time'], $duration);
        self::$stats['max_time'] = max(self::$stats['max_time'], $duration);
        
        if (self::$detailedTiming && isset(self::$timings[$operation])) {
            self::$timings[$operation]['duration'] = $duration;
            self::$timings[$operation]['end'] = microtime(true);
            
            // Keep only recent samples
            if (count(self::$timings) > self::$maxTimingSamples) {
                array_shift(self::$timings);
            }
        }
        
        return $duration;
    }
    
    /**
     * Record a cache hit
     */
    public static function recordCacheHit()
    {
        self::$stats['cache_hits']++;
    }
    
    /**
     * Record a cache miss
     */
    public static function recordCacheMiss()
    {
        self::$stats['cache_misses']++;
    }
    
    /**
     * Record a successful route match
     */
    public static function recordRouteMatch()
    {
        self::$stats['route_matches']++;
    }
    
    /**
     * Record a failed route match
     */
    public static function recordRouteFailure()
    {
        self::$stats['route_failures']++;
    }
    
    /**
     * Get comprehensive performance statistics
     * 
     * @return array Performance metrics
     */
    public static function getStats()
    {
        $totalRequests = self::$stats['total_requests'];
        $totalCacheRequests = self::$stats['cache_hits'] + self::$stats['cache_misses'];
        $totalRouteRequests = self::$stats['route_matches'] + self::$stats['route_failures'];
        
        $avgTime = $totalRequests > 0 ? self::$stats['total_time'] / $totalRequests : 0;
        $requestsPerSecond = self::$stats['total_time'] > 0 ? $totalRequests / self::$stats['total_time'] : 0;
        $cacheHitRatio = $totalCacheRequests > 0 ? self::$stats['cache_hits'] / $totalCacheRequests : 0;
        $routeSuccessRatio = $totalRouteRequests > 0 ? self::$stats['route_matches'] / $totalRouteRequests : 0;
        
        return array_merge(self::$stats, [
            'avg_response_time' => $avgTime,
            'requests_per_second' => $requestsPerSecond,
            'cache_hit_ratio' => $cacheHitRatio,
            'route_success_ratio' => $routeSuccessRatio,
            'total_cache_requests' => $totalCacheRequests,
            'total_route_requests' => $totalRouteRequests,
            'memory_usage' => memory_get_usage(),
            'peak_memory_usage' => memory_get_peak_usage()
        ]);
    }
    
    /**
     * Get detailed timing information
     * 
     * @return array Timing samples
     */
    public static function getDetailedTimings()
    {
        return self::$timings;
    }
    
    /**
     * Reset all statistics for FrankenPHP worker mode
     * Called automatically by FrankenPHP between requests.
     */
    public function reset(): void
    {
        self::$stats = [
            'total_requests' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'total_time' => 0,
            'min_time' => PHP_FLOAT_MAX,
            'max_time' => 0,
            'route_matches' => 0,
            'route_failures' => 0
        ];
        self::$timings = [];
    }
    
    /**
     * Enable or disable detailed timing collection
     * 
     * @param bool $enabled Whether to collect detailed timings
     */
    public static function setDetailedTiming($enabled)
    {
        self::$detailedTiming = $enabled;
        if (!$enabled) {
            self::$timings = [];
        }
    }
    
    /**
     * Set maximum number of timing samples to keep
     * 
     * @param int $maxSamples Maximum timing samples
     */
    public static function setMaxTimingSamples($maxSamples)
    {
        self::$maxTimingSamples = $maxSamples;
    }
    
    /**
     * Get performance report as formatted string
     * 
     * @return string Human-readable performance report
     */
    public static function getPerformanceReport()
    {
        $stats = self::getStats();
        
        $report = "=== Agavi Routing Performance Report ===\n";
        $report .= sprintf("Total Requests: %d\n", $stats['total_requests']);
        $report .= sprintf("Average Response Time: %.4f ms\n", $stats['avg_response_time'] * 1000);
        $report .= sprintf("Requests per Second: %.2f\n", $stats['requests_per_second']);
        $report .= sprintf("Min/Max Time: %.4f / %.4f ms\n", 
            $stats['min_time'] * 1000, $stats['max_time'] * 1000);
        
        if ($stats['total_cache_requests'] > 0) {
            $report .= sprintf("Cache Hit Ratio: %.2f%%\n", $stats['cache_hit_ratio'] * 100);
            $report .= sprintf("Cache Hits/Misses: %d/%d\n", 
                $stats['cache_hits'], $stats['cache_misses']);
        }
        
        if ($stats['total_route_requests'] > 0) {
            $report .= sprintf("Route Success Ratio: %.2f%%\n", $stats['route_success_ratio'] * 100);
            $report .= sprintf("Route Matches/Failures: %d/%d\n", 
                $stats['route_matches'], $stats['route_failures']);
        }
        
        $report .= sprintf("Memory Usage: %.2f MB\n", $stats['memory_usage'] / 1024 / 1024);
        $report .= sprintf("Peak Memory: %.2f MB\n", $stats['peak_memory_usage'] / 1024 / 1024);
        
        return $report;
    }
    
    /**
     * Export statistics for external monitoring systems
     * 
     * @param string $format Export format ('json', 'csv', 'xml')
     * @return string Exported data
     */
    public static function exportStats($format = 'json')
    {
        $stats = self::getStats();
        
        switch (strtolower($format)) {
            case 'json':
                return json_encode($stats, JSON_PRETTY_PRINT);
                
            case 'csv':
                $csv = implode(',', array_keys($stats)) . "\n";
                $csv .= implode(',', array_values($stats)) . "\n";
                return $csv;
                
            case 'xml':
                $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<routing_stats>\n";
                foreach ($stats as $key => $value) {
                    $xml .= "  <{$key}>{$value}</{$key}>\n";
                }
                $xml .= "</routing_stats>\n";
                return $xml;
                
            default:
                throw new InvalidArgumentException("Unsupported export format: {$format}");
        }
    }
}
