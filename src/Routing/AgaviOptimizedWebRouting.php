<?php
namespace Agavi\Routing;

use Agavi\AgaviContext;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Agavi Optimized Web Routing - High-performance routing implementation
 * 
 * This class extends AgaviWebRouting with advanced caching, trie-based
 * route matching, and performance monitoring for production environments.
 * Designed to work efficiently with FrankenPHP's persistent worker model.
 */
class AgaviOptimizedWebRouting extends AgaviWebRouting implements ResetInterface
{
    /**
     * @var AgaviRouteTrie Route trie instance
     */
    private $routeTrie = null;
    
    /**
     * @var bool Whether optimizations are enabled
     */
    private $optimizationsEnabled = true;
    
    /**
     * @var array Configuration options
     */
    private $config = [
        'enable_cache' => true,
        'enable_trie' => true,
        'enable_monitoring' => true,
        'cache_key_prefix' => 'agavi_route_',
        'detailed_timing' => false
    ];
    
    /**
     * Initialize the optimized routing
     * 
     * @param AgaviContext $context
     * @param array $parameters
     */
    public function initialize(AgaviContext $context, array $parameters = [])
    {
        parent::initialize($context, $parameters);
        
        // Merge configuration
        if (isset($parameters['optimizations'])) {
            $this->config = array_merge($this->config, $parameters['optimizations']);
        }
        
        // Configure performance monitoring
        if ($this->config['enable_monitoring']) {
            AgaviRoutingPerformanceMonitor::setDetailedTiming($this->config['detailed_timing']);
        }
    }
    
    /**
     * Execute routing with optimizations
     * 
     * @return AgaviExecutionContainer
     */
    public function execute()
    {
        if (!$this->optimizationsEnabled) {
            return parent::execute();
        }
        
        $startTime = null;
        if ($this->config['enable_monitoring']) {
            $startTime = AgaviRoutingPerformanceMonitor::startTiming();
        }
        
        try {
            $container = $this->executeOptimized();
            
            if ($this->config['enable_monitoring']) {
                AgaviRoutingPerformanceMonitor::endTiming($startTime);
                if ($container) {
                    AgaviRoutingPerformanceMonitor::recordRouteMatch();
                } else {
                    AgaviRoutingPerformanceMonitor::recordRouteFailure();
                }
            }
            
            return $container;
            
        } catch (\Exception $e) {
            if ($this->config['enable_monitoring']) {
                AgaviRoutingPerformanceMonitor::endTiming($startTime);
                AgaviRoutingPerformanceMonitor::recordRouteFailure();
            }
            throw $e;
        }
    }
    
    /**
     * Execute optimized routing logic
     * 
     * @return AgaviExecutionContainer
     */
    private function executeOptimized()
    {
        $request = $this->context->getRequest();
        $path = $request->getRequestUri();
        $method = $request->getMethod();
        
        // Try cache first
        if ($this->config['enable_cache']) {
            $container = $this->tryCache($path, $method);
            if ($container !== null) {
                return $container;
            }
        }
        
        // Build route trie if enabled and needed
        if ($this->config['enable_trie'] && $this->routeTrie === null) {
            $this->buildRouteTrie();
        }
        
        // Find and test routes
        $container = $this->matchRoute($path, $method);
        
        // Cache successful result
        if ($container && $this->config['enable_cache']) {
            $this->cacheResult($path, $method, $container);
        }
        
        return $container;
    }
    
    /**
     * Try to get result from cache
     * 
     * @param string $path Request path
     * @param string $method HTTP method
     * @return AgaviExecutionContainer|null
     */
    private function tryCache($path, $method)
    {
        $cacheKey = $this->generateCacheKey($path, $method);
        $cached = AgaviRouteCacheManager::get($cacheKey);
        
        if ($cached !== null) {
            if ($this->config['enable_monitoring']) {
                AgaviRoutingPerformanceMonitor::recordCacheHit();
            }
            
            return $this->buildContainerFromCache($cached);
        }
        
        if ($this->config['enable_monitoring']) {
            AgaviRoutingPerformanceMonitor::recordCacheMiss();
        }
        
        return null;
    }
    
    /**
     * Generate cache key for request
     * 
     * @param string $path Request path
     * @param string $method HTTP method
     * @return string Cache key
     */
    private function generateCacheKey($path, $method)
    {
        return $this->config['cache_key_prefix'] . md5($path . '|' . $method);
    }
    
    /**
     * Build execution container from cached data
     * 
     * @param array $cached Cached route data
     * @return AgaviExecutionContainer
     */
    private function buildContainerFromCache($cached)
    {
        $container = $this->context->getController()->createExecutionContainer();
        $container->setModuleName($cached['module']);
        $container->setActionName($cached['action']);
        
        // Restore request parameters
        if (!empty($cached['parameters'])) {
            $request = $this->context->getRequest();
            $requestData = $request->getRequestData();
            foreach ($cached['parameters'] as $key => $value) {
                $requestData->setParameter($key, $value);
            }
        }
        
        return $container;
    }
    
    /**
     * Build the route trie
     */
    private function buildRouteTrie()
    {
        if ($this->routes && $this->config['enable_trie']) {
            $this->routeTrie = AgaviRouteTrie::build($this->routes);
        }
    }
    
    /**
     * Match route using optimized algorithm
     * 
     * @param string $path Request path
     * @param string $method HTTP method
     * @return AgaviExecutionContainer|null
     */
    private function matchRoute($path, $method)
    {
        // Get candidate routes
        $candidates = $this->getCandidateRoutes($path);
        
        // Test candidates in order
        foreach ($candidates as $name => $route) {
            if ($this->testRoute($route, $path, $method)) {
                return $this->buildContainer($route, $name);
            }
        }
        
        // No match found - return 404 container
        return $this->build404Container();
    }
    
    /**
     * Get candidate routes for path
     * 
     * @param string $path Request path
     * @return array Candidate routes
     */
    private function getCandidateRoutes($path)
    {
        if ($this->config['enable_trie'] && $this->routeTrie !== null) {
            return AgaviRouteTrie::findCandidates($path);
        }
        
        // Fallback to all routes
        return $this->routes;
    }
    
    /**
     * Test if route matches the request
     * 
     * @param array $route Route definition
     * @param string $path Request path
     * @param string $method HTTP method
     * @return bool Whether route matches
     */
    private function testRoute($route, $path, $method)
    {
        // Check HTTP method constraints
        if (isset($route['method']) && !in_array($method, (array)$route['method'])) {
            return false;
        }
        
        // Check pattern
        $pattern = $this->getRoutePattern($route);
        if ($pattern && !preg_match($pattern, $path)) {
            return false;
        }
        
        // Additional constraint checks could go here
        return true;
    }
    
    /**
     * Get route pattern for matching
     * 
     * @param array $route Route definition
     * @return string|null Route pattern
     */
    private function getRoutePattern($route)
    {
        if (isset($route['pattern'])) {
            return $route['pattern'];
        }
        if (isset($route['rxp'])) {
            return $route['rxp'];
        }
        if (isset($route['opt']['pat'])) {
            return $route['opt']['pat'];
        }
        return null;
    }
    
    /**
     * Build execution container from route
     * 
     * @param array $route Route definition
     * @param string $routeName Route name
     * @return AgaviExecutionContainer
     */
    private function buildContainer($route, $routeName)
    {
        $container = $this->context->getController()->createExecutionContainer();
        
        // Set module and action
        if (isset($route['module'])) {
            $container->setModuleName($route['module']);
        }
        if (isset($route['action'])) {
            $container->setActionName($route['action']);
        }
        
        // Store route name for reference
        $container->setParameter('_route_name', $routeName);
        
        return $container;
    }
    
    /**
     * Build 404 error container
     * 
     * @return AgaviExecutionContainer
     */
    private function build404Container()
    {
        $container = $this->context->getController()->createExecutionContainer();
        $container->setModuleName('Default');
        $container->setActionName('Error404');
        return $container;
    }
    
    /**
     * Cache routing result
     * 
     * @param string $path Request path
     * @param string $method HTTP method
     * @param AgaviExecutionContainer $container Result container
     */
    private function cacheResult($path, $method, $container)
    {
        $cacheKey = $this->generateCacheKey($path, $method);
        
        $cacheData = [
            'module' => $container->getModuleName(),
            'action' => $container->getActionName(),
            'parameters' => []
        ];
        
        // Store relevant parameters
        $request = $this->context->getRequest();
        $requestData = $request->getRequestData();
        foreach ($requestData->getParameterNames() as $name) {
            if (strpos($name, '_') !== 0) { // Skip internal parameters
                $cacheData['parameters'][$name] = $requestData->getParameter($name);
            }
        }
        
        AgaviRouteCacheManager::set($cacheKey, $cacheData);
    }
    
    /**
     * Get comprehensive performance statistics
     * 
     * @return array Performance metrics
     */
    public function getPerformanceStats()
    {
        $stats = [];
        
        if ($this->config['enable_monitoring']) {
            $stats['routing'] = AgaviRoutingPerformanceMonitor::getStats();
        }
        
        if ($this->config['enable_cache']) {
            $stats['cache'] = AgaviRouteCacheManager::getStats();
        }
        
        if ($this->config['enable_trie']) {
            $stats['trie'] = AgaviRouteTrie::getStats();
        }
        
        $stats['callbacks'] = AgaviRoutingCallbackPool::getStats();
        
        return $stats;
    }
    
    /**
     * Clear all caches and reset optimizations
     */
    public function clearOptimizations()
    {
        AgaviRouteCacheManager::clear();
        AgaviRouteTrie::clear();
        AgaviRoutingCallbackPool::clearPool();
        AgaviRoutingPerformanceMonitor::getResetInstance()->reset();
        $this->routeTrie = null;
    }
    
    /**
     * Enable or disable optimizations
     * 
     * @param bool $enabled Whether optimizations are enabled
     */
    public function setOptimizationsEnabled($enabled)
    {
        $this->optimizationsEnabled = $enabled;
    }
    
    /**
     * Configure optimization settings
     * 
     * @param array $config Configuration options
     */
    public function setOptimizationConfig(array $config)
    {
        $this->config = array_merge($this->config, $config);
        
        if (isset($config['detailed_timing'])) {
            AgaviRoutingPerformanceMonitor::setDetailedTiming($config['detailed_timing']);
        }
    }
    
    /**
     * Get optimization configuration
     * 
     * @return array Current configuration
     */
    public function getOptimizationConfig()
    {
        return $this->config;
    }

    public function reset() : void
    {
        $this->clearOptimizations();
        $this->context = null;
        $this->routeTrie = null;
        $this->optimizationsEnabled = true;
        $this->config = [
            'enable_cache' => true,
            'enable_trie' => true,
            'enable_monitoring' => true,
            'cache_key_prefix' => 'agavi_route_',
            'detailed_timing' => false
        ];
    }
}
