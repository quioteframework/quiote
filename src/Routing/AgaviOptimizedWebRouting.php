<?php
namespace Agavi\Routing;

use Agavi\AgaviContext;
use Agavi\Config\AgaviConfig;
use Agavi\Util\AgaviToolkit;
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
     * @var bool Whether every route is a trivial (simple) route allowing fast path
     */
    private $allRoutesSimple = false;

    /**
     * @var bool Whether advanced features (callbacks, cut, imply etc.) require legacy engine
     */
    private $needsLegacy = false;
    /** @var bool Supports hierarchical optimized traversal (cut/imply/childs) */
    private $supportsHierarchy = false;
    
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

    // Analyze route set for feature complexity
    $this->analyzeRouteComplexity();
        
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
    // Normalize path: strip query string, ensure leading slash, convert empty to '/'
    $rawUri = $request->getRequestUri();
    $path = parse_url($rawUri, PHP_URL_PATH) ?? '/';
    if($path === '') { $path = '/'; }
    if($path[0] !== '/') { $path = '/' . $path; }
        $method = $request->getMethod();
        // If advanced features present, fall back to legacy engine (parity) with caching.
        if($this->needsLegacy) {
            if ($this->config['enable_cache']) {
                $cached = $this->tryCache($path, $method);
                if ($cached !== null) { return $cached; }
            }
            $container = parent::execute();
            if ($container && $this->config['enable_cache']) { $this->cacheResult($path, $method, $container); }
            return $container;
        }

        // Ensure we only optimize truly simple route sets.
        if(!$this->allRoutesSimple) {
            // Safety: if classification changed dynamically (routes imported later), recalc.
            $this->analyzeRouteComplexity();
            if($this->needsLegacy || !$this->allRoutesSimple) {
                $container = parent::execute();
                if ($container && $this->config['enable_cache']) { $this->cacheResult($path, $method, $container); }
                return $container;
            }
        }
        
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
        if(!empty($cached['matched_routes'])) {
            $this->context->getRequest()->setAttribute('matched_routes', $cached['matched_routes'], 'org.agavi.routing');
        }
        if(!empty($cached['output_type'])) {
            try { $container->setOutputType($this->context->getController()->getOutputType($cached['output_type'])); } catch(\Throwable) {}
        }
        if(!empty($cached['locale'])) {
            try { $tm = $this->context->getTranslationManager(); if($tm) { $tm->setLocale($cached['locale']); } } catch(\Throwable) {}
        }
        if(!empty($cached['request_method'])) {
            try { $this->context->getRequest()->setMethod($cached['request_method']); if(method_exists($container, 'setRequestMethod')) { $container->setRequestMethod($cached['request_method']); } } catch(\Throwable) {}
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
        if($this->supportsHierarchy) {
            $hier = $this->hierarchicalMatch($path, $method);
            if($hier) { return $hier; }
            return $this->build404Container();
        }
        $candidates = $this->getCandidateRoutes($path);
        foreach ($candidates as $name => $route) {
            $matchData = $this->testRoute($route, $path, $method);
            if ($matchData === false) { continue; }
            if (!is_array($matchData)) { continue; }
            return $this->buildContainer($route, $name, $matchData);
        }
        return $this->build404Container();
    }

    /**
     * Optimized hierarchical matcher supporting cut/imply/child relationships (no callbacks or sources).
     */
    private function hierarchicalMatch(string $input, string $method)
    {
        $request = $this->context->getRequest();
        $requestData = $request->getRequestData();
        $container = $this->context->getController()->createExecutionContainer();
        $matchedRoutes = [];
        $vars = [];

        // root routes
        $root = [];
        foreach($this->routes as $n => $r) { if(!$r['opt']['parent']) { $root[] = $n; } }
        $stack = [$root];
        while(($list = array_pop($stack)) !== null) {
            foreach($list as $rName) {
                $route =& $this->routes[$rName];
                $opt = $route['opt'];
                if(!empty($opt['constraint']) && !in_array($method, (array)$opt['constraint'], true)) { continue; }
                $matches = [];
                if(!$this->rawMatchRoute($route, $input, $matches)) { continue; }

                $matchedRoutes[] = $opt['name'];
                $ignores = !empty($opt['ignores']) ? array_flip($opt['ignores']) : [];
                if(!empty($opt['defaults'])) {
                    foreach($opt['defaults'] as $k=>$rv) { if(isset($ignores[$k])) continue; if(is_object($rv)&&method_exists($rv,'getValue')) { $v=$rv->getValue(); if($v!==null) { $vars[$k]=$v; } } }
                }
                if(!empty($route['par'])) {
                    foreach($route['par'] as $p) { if(isset($ignores[$p])) continue; if(isset($matches[$p]) && $matches[$p][1] != -1) { $val=$matches[$p][0]; $vars[$p]=$val; $route['matches'][$p]=$val; } }
                }
                if(!empty($opt['module'])) { $container->setModuleName($opt['module']); }
                if(!empty($opt['action'])) { $container->setActionName($opt['action']); }
                // Variable expansion support (parity with buildContainer path)
                $expandVars = $vars;
                if(!empty($opt['output_type'])) {
                    try {
                        $ot = method_exists(AgaviToolkit::class,'expandVariables') ? AgaviToolkit::expandVariables($opt['output_type'],$expandVars) : $opt['output_type'];
                        if($ot !== null && $ot !== '') { $container->setOutputType($this->context->getController()->getOutputType($ot)); }
                    } catch(\Throwable) {}
                }
                if(!empty($opt['locale'])) {
                    try { $tm=$this->context->getTranslationManager(); if($tm) {
                        $loc = method_exists(AgaviToolkit::class,'expandVariables') ? AgaviToolkit::expandVariables($opt['locale'],$expandVars) : $opt['locale'];
                        if($loc !== null && $loc !== '') { $tm->setLocale($loc); }
                    } } catch(\Throwable) {}
                }
                if(!empty($opt['method'])) {
                    try {
                        $nm = method_exists(AgaviToolkit::class,'expandVariables') ? AgaviToolkit::expandVariables($opt['method'],$expandVars) : $opt['method'];
                        if($nm !== null && $nm !== '') { $request->setMethod($nm); if(method_exists($container,'setRequestMethod')) { $container->setRequestMethod($nm); } }
                    } catch(\Throwable) {}
                }

                // cut semantics
                if($opt['cut'] || (count($opt['childs']) && $opt['cut'] === null)) {
                    $m0 = $matches[0];
                    $newInput = '';
                    if($m0[1] > 0) { $newInput = substr((string)$input, 0, $m0[1]); }
                    $newInput .= substr((string)$input, $m0[1] + strlen((string)$m0[0]));
                    $input = $newInput;
                }

                if(count($opt['childs'])) { $stack[] = $opt['childs']; break; }
                if($opt['stop']) { break 2; }
            }
        }
        // Handle implied nostops for last matched route chain (simple approximation): when a route matched we include any nostops flagged as imply
        // This approximates legacy getAffectedRoutes() inclusion semantics for generation context.
        if($matchedRoutes) {
            // Iterate matched routes in reverse to gather nostops
            $extra = [];
            foreach(array_reverse($matchedRoutes) as $mr) {
                $r = $this->routes[$mr];
                foreach(array_reverse($r['opt']['nostops']) as $ns) {
                    $nr = $this->routes[$ns];
                    if(!$nr['opt']['imply']) { continue; }
                    if(!in_array($ns, $matchedRoutes, true) && !in_array($ns, $extra, true)) { $extra[] = $ns; }
                }
            }
            if($extra) { $matchedRoutes = array_merge($matchedRoutes, $extra); }
        }
        foreach($vars as $k=>$v) { $requestData->setParameter($k,$v); }
        if(!$matchedRoutes) { return null; }
        if($container->getModuleName() === null || $container->getActionName() === null) {
            $errMod = AgaviConfig::get('actions.error_404_module', 'Default');
            $errAct = AgaviConfig::get('actions.error_404_action', 'Error404');
            $container->setModuleName($errMod); $container->setActionName($errAct);
        }
        $request->setAttribute('matched_routes', $matchedRoutes, 'org.agavi.routing');
        return $container;
    }

    private function rawMatchRoute(array $route, string $input, array &$matches): bool
    {
        $pattern = $this->getRoutePattern($route);
        if(!$pattern) { return false; }
        if(!preg_match($pattern, $input, $matches, PREG_OFFSET_CAPTURE)) {
            if(!($input === '/' && preg_match($pattern, '', $matches, PREG_OFFSET_CAPTURE))) { return false; }
        }
        return true;
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
    private function testRoute(array &$route, string $path, string $method): array|false
    {
        $opt = $route['opt'] ?? [];
    // Unsupported advanced features -> skip (legacy engine handles). 'imply' affects generation only, so allow it.
    if(!empty($opt['cut']) || !empty($opt['childs']) || !empty($opt['callbacks']) || $opt['source'] !== null) {
            return false;
        }
        // Enforce HTTP method constraint if present
        if(!empty($opt['constraint']) && !in_array($method, (array)$opt['constraint'], true)) {
            return false;
        }
        $pattern = $this->getRoutePattern($route);
        if(!$pattern) { return false; }
        $matches = [];
        if(!preg_match($pattern, $path, $matches, PREG_OFFSET_CAPTURE)) {
            if(!($path === '/' && preg_match($pattern, '', $matches, PREG_OFFSET_CAPTURE))) { return false; }
        }
        $ignores = [];
        if(!empty($opt['ignores'])) { $ignores = array_flip($opt['ignores']); }
        $vars = [];
        if(!empty($opt['defaults'])) {
            foreach($opt['defaults'] as $k => $routingValue) {
                if(isset($ignores[$k])) { continue; }
                if(is_object($routingValue) && method_exists($routingValue, 'getValue')) {
                    $val = $routingValue->getValue();
                    if($val !== null) { $vars[$k] = $val; }
                }
            }
        }
        if(!empty($route['par'])) {
            foreach($route['par'] as $paramName) {
                if(isset($ignores[$paramName])) { continue; }
                if(isset($matches[$paramName]) && $matches[$paramName][1] != -1) {
                    $vars[$paramName] = $matches[$paramName][0];
                    $route['matches'][$paramName] = $matches[$paramName][0];
                }
            }
        }
        return ['vars' => $vars];
    }
    
    /**
     * Analyze all routes to determine if we can apply the simple fast path or must fall back.
     * Simple route definition criteria (all must hold):
     * - No childs, callbacks, implies, cut, ignores, defaults, source, constraint, output_type, locale
     * - Has explicit module & action
     * - No pattern parameters (par empty)
     */
    private function analyzeRouteComplexity(): void
    {
        if(!$this->routes) {
            $this->allRoutesSimple = true;
            $this->needsLegacy = false;
            return;
        }
    $allSimple = true;
    $needsLegacy = false;
    $this->supportsHierarchy = true; // assume until disproven
    foreach($this->routes as $route) {
            $opt = $route['opt'];
            $hasChilds = !empty($opt['childs']);
            $hasCallbacks = !empty($opt['callbacks']);
            $hasImply = !empty($opt['imply']);
            $hasCut = !empty($opt['cut']);
            $hasIgnores = !empty($opt['ignores']);
            $hasDefaults = !empty($opt['defaults']);
            $hasSource = $opt['source'] !== null;
            $hasConstraint = !empty($opt['constraint']);
            $hasOutputType = !empty($opt['output_type']);
            $hasLocale = !empty($opt['locale']);
            $hasMethodTransform = !empty($opt['method']);
            $hasModule = !empty($opt['module']);
            $hasAction = !empty($opt['action']);
            $hasParams = !empty($route['par']);
            // Simple path now allows params/defaults/ignores; everything else delegates
            // Allow output_type & locale in optimized path now
            // Allow constraints and method transform in optimized path now
            // Allow hierarchy (childs/cut) and imply flag in optimized path; callbacks or sources still force legacy
            if($hasCallbacks || $hasSource) { $allSimple = false; $needsLegacy = true; $this->supportsHierarchy = false; }
            if(!$hasModule || !$hasAction) { $needsLegacy = true; $allSimple = false; }
        }
        $this->allRoutesSimple = $allSimple;
        $this->needsLegacy = $needsLegacy;
        if(!$this->supportsHierarchy) { /* left false when callbacks/sources present */ }
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
    private function buildContainer(array $route, string $routeName, array $matchData)
    {
        $container = $this->context->getController()->createExecutionContainer();
        $opt = $route['opt'];
        $request = $this->context->getRequest();
        $requestData = $request->getRequestData();
        foreach($matchData['vars'] as $k => $v) { $requestData->setParameter($k, $v); }
        if(!empty($opt['module'])) { $container->setModuleName($opt['module']); }
        if(!empty($opt['action'])) { $container->setActionName($opt['action']); }
        // Support output_type and locale propagation
        $expandVars = $matchData['vars'];
        // Method transformation (route 'method' option sets/overrides request method)
        if(!empty($opt['method'])) {
            try {
                $newMethod = method_exists(AgaviToolkit::class, 'expandVariables') ? AgaviToolkit::expandVariables($opt['method'], $expandVars) : $opt['method'];
                if($newMethod) {
                    $request->setMethod($newMethod);
                    if(method_exists($container, 'setRequestMethod')) { $container->setRequestMethod($newMethod); }
                }
            } catch(\Throwable) {}
        }
        if(!empty($opt['output_type'])) {
            try {
                $otName = method_exists(AgaviToolkit::class, 'expandVariables') ? AgaviToolkit::expandVariables($opt['output_type'], $expandVars) : $opt['output_type'];
                if($otName !== null && $otName !== '') {
                    try { $container->setOutputType($this->context->getController()->getOutputType($otName)); } catch(\Throwable) {}
                }
            } catch(\Throwable) {}
        }
        if(!empty($opt['locale'])) {
            try {
                $tm = $this->context->getTranslationManager();
                if($tm) {
                    $localeName = method_exists(AgaviToolkit::class, 'expandVariables') ? AgaviToolkit::expandVariables($opt['locale'], $expandVars) : $opt['locale'];
                    if($localeName !== null && $localeName !== '') { try { $tm->setLocale($localeName); } catch(\Throwable) {} }
                }
            } catch(\Throwable) {}
        }
        if($container->getModuleName() === null || $container->getActionName() === null) {
            $errMod = AgaviConfig::get('actions.error_404_module', 'Default');
            $errAct = AgaviConfig::get('actions.error_404_action', 'Error404');
            $container->setModuleName($errMod);
            $container->setActionName($errAct);
        }
        $request->setAttribute('matched_routes', [$routeName], 'org.agavi.routing');
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
        $errMod = AgaviConfig::get('actions.error_404_module', 'Default');
        $errAct = AgaviConfig::get('actions.error_404_action', 'Error404');
        $container->setModuleName($errMod);
        $container->setActionName($errAct);
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
        
        // Skip caching 404 container so new routes become visible without worker restart
        $errMod = AgaviConfig::get('actions.error_404_module', 'Default');
        $errAct = AgaviConfig::get('actions.error_404_action', 'Error404');
        if($container->getModuleName() === $errMod && $container->getActionName() === $errAct) {
            return; // don't cache 404
        }

        $cacheData = [
            'module' => $container->getModuleName(),
            'action' => $container->getActionName(),
            'parameters' => [],
            'matched_routes' => $this->context->getRequest()->getAttribute('matched_routes', 'org.agavi.routing', []),
            'output_type' => $container->getOutputType() ? $container->getOutputType()->getName() : null,
            'locale' => ($this->context->getTranslationManager()?->getCurrentLocaleIdentifier()) ?? null,
            'request_method' => method_exists($container, 'getRequestMethod') ? $container->getRequestMethod() : $this->context->getRequest()->getMethod(),
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
