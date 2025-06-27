<?php
namespace Agavi\Routing;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Agavi Route Trie - Fast route matching using prefix tree data structure
 * 
 * This class implements a trie (prefix tree) for efficient route matching.
 * Routes are organized by their first path segment to reduce the number
 * of regex tests needed for route resolution.
 */
class AgaviRouteTrie implements ResetInterface
{
    /**
     * @var self|null Singleton instance for ResetInterface
     */
    private static $resetInstance = null;
    
    /**
     * @var array|null The route trie structure
     */
    private static $trie = null;
    
    /**
     * @var bool Whether the trie has been optimized
     */
    private static $optimized = false;
    
    /**
     * @var array Statistics about trie usage
     */
    private static $stats = [
        'builds' => 0,
        'lookups' => 0,
        'candidates_found' => 0
    ];
    
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
     * Build the route trie from route definitions
     * 
     * @param array $routes Route definitions
     * @return array The built trie structure
     */
    public static function build($routes)
    {
        if (self::$trie !== null && self::$optimized) {
            return self::$trie;
        }
        
        self::$trie = ['routes' => [], 'children' => []];
        self::$stats['builds']++;
        
        foreach ($routes as $name => $route) {
            self::insertRoute($name, $route);
        }
        
        self::optimize();
        return self::$trie;
    }
    
    /**
     * Insert a route into the trie
     * 
     * @param string $name Route name
     * @param array $route Route definition
     */
    private static function insertRoute($name, $route)
    {
        // Extract pattern from route definition
        $pattern = '';
        if (isset($route['pattern'])) {
            $pattern = $route['pattern'];
        } elseif (isset($route['rxp'])) {
            $pattern = $route['rxp'];
        } elseif (isset($route['opt']['pat'])) {
            $pattern = $route['opt']['pat'];
        }
        
        // Extract first significant path segment
        $firstSegment = self::extractFirstSegment($pattern);
        
        if (!isset(self::$trie['children'][$firstSegment])) {
            self::$trie['children'][$firstSegment] = ['routes' => [], 'children' => []];
        }
        
        self::$trie['children'][$firstSegment]['routes'][$name] = $route;
    }
    
    /**
     * Extract the first significant segment from a route pattern
     * 
     * @param string $pattern Route pattern
     * @return string First segment or '_root' for complex patterns
     */
    private static function extractFirstSegment($pattern)
    {
        // Remove leading anchors and slashes
        $pattern = ltrim($pattern, '^/');
        
        // Handle root patterns
        if (empty($pattern) || $pattern === '$' || $pattern === '^$') {
            return '_root';
        }
        
        // Extract first literal segment
        if (preg_match('#^([^/\(\[\{\\\\]+)#', $pattern, $matches)) {
            return strtolower($matches[1]);
        }
        
        // Handle patterns starting with parameters or complex regex
        if (preg_match('#^[^/]*?/([^/\(\[\{\\\\]+)#', $pattern, $matches)) {
            return strtolower($matches[1]);
        }
        
        return '_root';
    }
    
    /**
     * Find candidate routes for a given path
     * 
     * @param string $path Request path
     * @return array Candidate routes
     */
    public static function findCandidates($path)
    {
        if (self::$trie === null) {
            return [];
        }
        
        self::$stats['lookups']++;
        
        // Clean and parse path
        $path = trim($path, '/');
        $segments = $path ? explode('/', $path) : [''];
        $firstSegment = strtolower($segments[0]);
        
        $candidates = [];
        
        // Check specific segment
        if (isset(self::$trie['children'][$firstSegment])) {
            $candidates = array_merge($candidates, self::$trie['children'][$firstSegment]['routes']);
        }
        
        // Always check root routes for catch-all patterns
        if (isset(self::$trie['children']['_root'])) {
            $candidates = array_merge($candidates, self::$trie['children']['_root']['routes']);
        }
        
        // Check empty segment for root requests
        if ($firstSegment !== '' && isset(self::$trie['children'][''])) {
            $candidates = array_merge($candidates, self::$trie['children']['']['routes']);
        }
        
        self::$stats['candidates_found'] += count($candidates);
        return $candidates;
    }
    
    /**
     * Optimize the trie structure
     */
    private static function optimize()
    {
        if (self::$trie === null) {
            return;
        }
        
        // Sort routes by priority/specificity
        foreach (self::$trie['children'] as $segment => &$node) {
            if (!empty($node['routes'])) {
                uasort($node['routes'], [self::class, 'compareRoutePriority']);
            }
        }
        
        self::$optimized = true;
    }
    
    /**
     * Compare route priority for sorting
     * 
     * @param array $routeA First route
     * @param array $routeB Second route
     * @return int Comparison result
     */
    private static function compareRoutePriority($routeA, $routeB)
    {
        // Routes with higher priority come first
        $priorityA = isset($routeA['priority']) ? $routeA['priority'] : 0;
        $priorityB = isset($routeB['priority']) ? $routeB['priority'] : 0;
        
        if ($priorityA !== $priorityB) {
            return $priorityB - $priorityA; // Higher priority first
        }
        
        // More specific patterns come first (fewer wildcards)
        $patternA = $routeA['pattern'] ?? $routeA['rxp'] ?? '';
        $patternB = $routeB['pattern'] ?? $routeB['rxp'] ?? '';
        
        $wildcardCountA = substr_count($patternA, '(') + substr_count($patternA, '*') + substr_count($patternA, '+');
        $wildcardCountB = substr_count($patternB, '(') + substr_count($patternB, '*') + substr_count($patternB, '+');
        
        return $wildcardCountA - $wildcardCountB;
    }
    
    /**
     * Clear the trie and reset optimization
     */
    public static function clear()
    {
        self::$trie = null;
        self::$optimized = false;
    }
    
    /**
     * Get trie statistics
     * 
     * @return array Trie performance stats
     */
    public static function getStats()
    {
        $routeCount = 0;
        $segmentCount = 0;
        
        if (self::$trie !== null) {
            $segmentCount = count(self::$trie['children']);
            foreach (self::$trie['children'] as $node) {
                $routeCount += count($node['routes']);
            }
        }
        
        return array_merge(self::$stats, [
            'route_count' => $routeCount,
            'segment_count' => $segmentCount,
            'optimized' => self::$optimized,
            'avg_candidates' => self::$stats['lookups'] > 0 ? 
                self::$stats['candidates_found'] / self::$stats['lookups'] : 0
        ]);
    }
    
    /**
     * Get the raw trie structure (for debugging)
     * 
     * @return array|null Trie structure
     */
    public static function getTrieStructure()
    {
        return self::$trie;
    }

    /**
     * Reset trie state for FrankenPHP worker mode.
     * Called automatically by FrankenPHP between requests.
     * In worker mode, we typically want to preserve the trie for performance,
     * but reset statistics.
     */
    public function reset(): void
    {
        // By default, preserve trie but reset statistics for worker mode
        self::$stats = [
            'builds' => 0,
            'lookups' => 0,
            'candidates_found' => 0
        ];
        // Note: Trie is preserved for performance in worker mode
        // Use clear() method if you need to clear the trie entirely
    }
}
